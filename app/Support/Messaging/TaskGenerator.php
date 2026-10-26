<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Models\Sale;
use App\Support\ClinicTime;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotent message-task generation, ported from
 * src/modules/messaging/generate.ts.
 *
 * The unique tuple (source_type, source_id, template_key, patient_id) makes
 * every step safe to repeat — the job can run as often as the scheduler likes.
 * A reschedule is the one case that intentionally makes a NEW row: the old
 * tasks are CANCELLED and a versioned source id is used.
 */
final class TaskGenerator
{
    /** The task keys an appointment owns (cancelled when the appointment ends). */
    public const APPOINTMENT_SCHEDULE_KEYS = ['APPT_REMINDER', 'APPT_SAME_DAY', 'APPT_CONFIRMATION'];

    /** The open (not yet handled) task statuses. */
    public const OPEN_STATUSES = [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED];

    /**
     * The messaging settings (timezone, reminder time, offsets, …).
     *
     * @return array{timeZone: string, reminderSendTime: string, reminderSendByOffsetMinutes: int, followupLeadDays: int, recallMonths: int}
     */
    public static function settings(): array
    {
        return [
            'timeZone' => Settings::timezone(),
            'reminderSendTime' => (string) Settings::get('reminder_send_time', '14:00'),
            'reminderSendByOffsetMinutes' => (int) Settings::get('reminder_send_by_offset_minutes', 60),
            'followupLeadDays' => (int) Settings::get('followup_lead_days', 3),
            'recallMonths' => (int) Settings::get('recall_months', 12),
        ];
    }

    /**
     * Creates one task unless the unique tuple already exists.
     *
     * @param  array<string, mixed>  $input
     * @return string 'created' or 'exists'
     */
    public static function createTask(array $input): string
    {
        $patient = $input['patient'];

        $reason = Eligibility::blockedReason([
            'patient' => [
                'active' => (bool) $patient['active'],
                'doNotMessage' => (bool) $patient['doNotMessage'],
                'phoneE164' => (string) $patient['phoneE164'],
            ],
            'sourceStatus' => $input['sourceStatus'] ?? null,
        ]);

        try {
            MessageTask::create([
                'patient_id' => $patient['id'],
                'template_key' => $input['templateKey'],
                'source_type' => $input['sourceType'],
                'source_id' => $input['sourceId'],
                'campaign_id' => $input['campaignId'] ?? null,
                'campaign_batch_id' => $input['campaignBatchId'] ?? null,
                'language' => $input['language'],
                'rendered_message' => $input['renderedMessage'],
                'due_at' => $input['dueAt'],
                'send_by' => $input['sendBy'] ?? null,
                'expires_at' => $input['expiresAt'] ?? null,
                'status' => $reason === null ? MessageTask::STATUS_PENDING : MessageTask::STATUS_BLOCKED,
                'blocked_reason' => $reason,
            ]);

            return 'created';
        } catch (UniqueConstraintViolationException) {
            return 'exists';
        }
    }

    /**
     * Cancels the open tasks of one source (and optionally of some keys).
     *
     * @param  list<string>  $keys
     */
    public static function cancelPendingScope(string $sourceType, string $sourceIdPrefix, array $keys = []): int
    {
        $query = MessageTask::query()
            ->where('source_type', $sourceType)
            ->where('source_id', 'like', self::likePrefix($sourceIdPrefix))
            ->whereIn('status', self::OPEN_STATUSES);

        if ($keys !== []) {
            $query->whereIn('template_key', $keys);
        }

        return $query->update(['status' => MessageTask::STATUS_CANCELLED]);
    }

    /** Expires every open task whose deadline has passed. */
    public static function expireTasks(?\DateTimeInterface $now = null): int
    {
        $now ??= ClinicTime::nowUtc();

        return MessageTask::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->update(['status' => MessageTask::STATUS_EXPIRED]);
    }

    /** Cancels every open task of a patient (the opt-out flow). */
    public static function cancelPendingForPatient(string $patientId): int
    {
        return MessageTask::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->update(['status' => MessageTask::STATUS_CANCELLED]);
    }

    /**
     * The appointment hook: creates the reminder / same-day / confirmation
     * tasks, or the thank-you / no-show task, and cancels what is no longer
     * relevant.
     */
    public static function onAppointmentChanged(
        string $appointmentId,
        ?\DateTimeInterface $previousStartsAt = null,
        ?\DateTimeInterface $now = null,
    ): void {
        $appointment = Appointment::query()
            ->with(['patient', 'doctor'])
            ->find($appointmentId);

        if ($appointment === null) {
            return;
        }

        $config = self::settings();
        $bodies = MsgQueries::loadTemplateBodies();
        $now ??= ClinicTime::nowUtc();
        $patient = self::patientPayload($appointment->patient);

        $apptLike = [
            'id' => $appointment->id,
            'startsAt' => $appointment->starts_at,
            'createdAt' => $appointment->created_at,
        ];

        // A reschedule cancels the old pair and re-creates a versioned one.
        $rescheduled = $previousStartsAt !== null
            && $previousStartsAt->getTimestamp() !== $appointment->starts_at?->getTimestamp();

        if ($rescheduled) {
            self::cancelPendingScope('APPOINTMENT', $appointment->id, ['APPT_REMINDER', 'APPT_SAME_DAY']);
        }

        $context = self::appointmentContext(
            $patient,
            $config,
            $appointment->doctor?->full_name ?? '',
            $appointment->starts_at,
        );

        $sourceStatus = (string) $appointment->status;

        $makeTask = function (string $key, string $sourceId, ?array $schedule) use ($patient, $context, $bodies, $sourceStatus): ?string {
            if ($schedule === null) {
                return null;
            }

            return self::createTask([
                'patient' => $patient,
                'templateKey' => $key,
                'sourceType' => 'APPOINTMENT',
                'sourceId' => $sourceId,
                'language' => $context['language'],
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, $key, $context['language']),
                    $context,
                ),
                'sourceStatus' => $sourceStatus,
                'dueAt' => $schedule['dueAt'],
                'sendBy' => $schedule['sendBy'],
                'expiresAt' => $schedule['expiresAt'],
            ]);
        };

        if ($sourceStatus === Appointment::STATUS_SCHEDULED || $sourceStatus === Appointment::STATUS_CONFIRMED) {
            $versionId = $appointment->id.'#'.($appointment->starts_at?->getTimestamp() * 1000);

            $makeTask('APPT_REMINDER', $versionId, Schedule::appointmentReminderSchedule($apptLike, $config, $now));
            $makeTask('APPT_SAME_DAY', $versionId, Schedule::appointmentSameDaySchedule($apptLike, $config, $now));

            if ($sourceStatus === Appointment::STATUS_CONFIRMED) {
                $makeTask('APPT_CONFIRMATION', $appointment->id, Schedule::appointmentConfirmationSchedule($now, $config));
            }

            return;
        }

        if ($sourceStatus === Appointment::STATUS_COMPLETED) {
            self::cancelPendingScope('APPOINTMENT', $appointment->id, self::APPOINTMENT_SCHEDULE_KEYS);

            self::createTask([
                'patient' => $patient,
                'templateKey' => 'THANK_YOU',
                'sourceType' => 'APPOINTMENT',
                'sourceId' => $appointment->id,
                'language' => $context['language'],
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'THANK_YOU', $context['language']),
                    $context,
                ),
                'sourceStatus' => $sourceStatus,
                ...Schedule::thankYouSchedule($apptLike, $config),
            ]);

            return;
        }

        if ($sourceStatus === Appointment::STATUS_NO_SHOW) {
            self::cancelPendingScope('APPOINTMENT', $appointment->id, self::APPOINTMENT_SCHEDULE_KEYS);

            self::createTask([
                'patient' => $patient,
                'templateKey' => 'NO_SHOW',
                'sourceType' => 'APPOINTMENT',
                'sourceId' => $appointment->id,
                'language' => $context['language'],
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'NO_SHOW', $context['language']),
                    $context,
                ),
                'sourceStatus' => $sourceStatus,
                ...Schedule::noShowSchedule($now, $config),
            ]);

            return;
        }

        if ($sourceStatus === Appointment::STATUS_CANCELLED) {
            self::cancelPendingScope('APPOINTMENT', $appointment->id, self::APPOINTMENT_SCHEDULE_KEYS);
        }
    }

    /**
     * "Notify patient of change" — creates an immediate schedule-change task.
     *
     * @return string 'created', 'exists' or 'missing'
     */
    public static function generateScheduleChangeTask(string $appointmentId, ?\DateTimeInterface $now = null): string
    {
        $appointment = Appointment::query()->with(['patient', 'doctor'])->find($appointmentId);

        if ($appointment === null) {
            return 'missing';
        }

        $config = self::settings();
        $bodies = MsgQueries::loadTemplateBodies();
        $patient = self::patientPayload($appointment->patient);

        $context = self::appointmentContext(
            $patient,
            $config,
            $appointment->doctor?->full_name ?? '',
            $appointment->starts_at,
        );

        // Every click makes a NEW task (the timestamp is part of the source id).
        $sourceId = $appointment->id.'#sc#'.ClinicTime::nowUtc()->getTimestamp();

        return self::createTask([
            'patient' => $patient,
            'templateKey' => 'SCHEDULE_CHANGE',
            'sourceType' => 'APPOINTMENT',
            'sourceId' => $sourceId,
            'language' => $context['language'],
            'renderedMessage' => Render::renderMessage(
                Render::bodyFor($bodies, 'SCHEDULE_CHANGE', $context['language']),
                $context,
            ),
            'sourceStatus' => (string) $appointment->status,
            ...Schedule::immediateSchedule($now ?? ClinicTime::nowUtc()),
        ]);
    }

    /** The follow-up hook. */
    public static function onFollowUpChanged(string $followUpId, ?\DateTimeInterface $now = null): void
    {
        $followUp = FollowUp::query()->with('patient')->find($followUpId);

        if ($followUp === null) {
            return;
        }

        if ((string) $followUp->status !== FollowUp::STATUS_OPEN) {
            self::cancelPendingScope('FOLLOWUP', $followUp->id, ['FOLLOWUP_DUE']);

            return;
        }

        $config = self::settings();
        $bodies = MsgQueries::loadTemplateBodies();
        $patient = self::patientPayload($followUp->patient);
        $language = Render::resolveLanguage($patient['preferredLanguage']);

        $context = [
            'patientName' => $patient['fullName'],
            'language' => $language,
            'clinicName' => (string) Settings::get('clinic_name', ''),
            'reviewLink' => (string) Settings::get('review_link', ''),
            'timeZone' => $config['timeZone'],
        ];

        self::createTask([
            'patient' => $patient,
            'templateKey' => 'FOLLOWUP_DUE',
            'sourceType' => 'FOLLOWUP',
            'sourceId' => $followUp->id,
            'language' => $language,
            'renderedMessage' => Render::renderMessage(
                Render::bodyFor($bodies, 'FOLLOWUP_DUE', $language),
                $context,
            ),
            'sourceStatus' => (string) $followUp->status,
            ...Schedule::followUpSchedule($followUp->due_date, $config, $now ?? ClinicTime::nowUtc()),
        ]);
    }

    /**
     * The welcome task — created when a patient gives consent (the
     * do-not-message flag is turned off).
     */
    public static function onPatientConsentGiven(string $patientId, ?\DateTimeInterface $now = null): void
    {
        $patient = Patient::query()->find($patientId);

        if ($patient === null) {
            return;
        }

        $config = self::settings();
        $bodies = MsgQueries::loadTemplateBodies();
        $payload = self::patientPayload($patient);
        $language = Render::resolveLanguage($payload['preferredLanguage']);

        $context = [
            'patientName' => $payload['fullName'],
            'language' => $language,
            'clinicName' => (string) Settings::get('clinic_name', ''),
            'reviewLink' => (string) Settings::get('review_link', ''),
            'timeZone' => $config['timeZone'],
        ];

        self::createTask([
            'patient' => $payload,
            'templateKey' => 'WELCOME',
            'sourceType' => 'MANUAL',
            'sourceId' => $patient->id.'#welcome',
            'language' => $language,
            'renderedMessage' => Render::renderMessage(
                Render::bodyFor($bodies, 'WELCOME', $language),
                $context,
            ),
            'sourceStatus' => null,
            ...Schedule::immediateSchedule($now ?? ClinicTime::nowUtc()),
        ]);
    }

    /**
     * Runs every automatic generation step + the expiry. Safe to repeat.
     *
     * @return array{expired: int, sameDay: int, thankYous: int, followUps: int, recalls: int}
     */
    public static function runMessageJobs(?\DateTimeInterface $now = null): array
    {
        $now ??= ClinicTime::nowUtc();
        $config = self::settings();

        return [
            'expired' => self::expireTasks($now),
            'sameDay' => self::generateSameDay($now, $config),
            'thankYous' => self::generateThankYous($now, $config),
            'followUps' => self::generateFollowUps($now, $config),
            'recalls' => self::generateRecalls($now, $config),
        ];
    }

    /**
     * The same-day reminder for every appointment of today.
     *
     * @param  array<string, mixed>  $config
     */
    public static function generateSameDay(\DateTimeInterface $now, array $config): int
    {
        $tz = $config['timeZone'];
        $todayKey = ClinicTime::clinicDateKey($now, $tz);

        $appointments = Appointment::query()
            ->with(['patient', 'doctor'])
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', ClinicTime::combineDateAndTime($todayKey, '00:00', $tz))
            ->where('starts_at', '<', ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($todayKey, 1), '00:00', $tz))
            ->take(500)
            ->get();

        $bodies = MsgQueries::loadTemplateBodies();
        $created = 0;

        foreach ($appointments as $appointment) {
            $schedule = Schedule::appointmentSameDaySchedule([
                'id' => $appointment->id,
                'startsAt' => $appointment->starts_at,
                'createdAt' => $appointment->created_at,
            ], $config, $now);

            if ($schedule === null) {
                continue;
            }

            $patient = self::patientPayload($appointment->patient);
            $context = self::appointmentContext(
                $patient,
                $config,
                $appointment->doctor?->full_name ?? '',
                $appointment->starts_at,
            );

            $result = self::createTask([
                'patient' => $patient,
                'templateKey' => 'APPT_SAME_DAY',
                'sourceType' => 'APPOINTMENT',
                'sourceId' => $appointment->id.'#'.($appointment->starts_at?->getTimestamp() * 1000),
                'language' => $context['language'],
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'APPT_SAME_DAY', $context['language']),
                    $context,
                ),
                'sourceStatus' => Appointment::STATUS_CONFIRMED,
                'dueAt' => $schedule['dueAt'],
                'sendBy' => $schedule['sendBy'],
                'expiresAt' => $schedule['expiresAt'],
            ]);

            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The thank-you for every appointment completed yesterday.
     *
     * @param  array<string, mixed>  $config
     */
    public static function generateThankYous(\DateTimeInterface $now, array $config): int
    {
        $tz = $config['timeZone'];
        $yesterdayKey = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey($now, $tz), -1);

        $appointments = Appointment::query()
            ->with(['patient', 'doctor'])
            ->where('status', Appointment::STATUS_COMPLETED)
            ->where('starts_at', '>=', ClinicTime::combineDateAndTime($yesterdayKey, '00:00', $tz))
            ->where('starts_at', '<', ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($yesterdayKey, 1), '00:00', $tz))
            ->take(500)
            ->get();

        $bodies = MsgQueries::loadTemplateBodies();
        $created = 0;

        foreach ($appointments as $appointment) {
            $patient = self::patientPayload($appointment->patient);
            $context = self::appointmentContext(
                $patient,
                $config,
                $appointment->doctor?->full_name ?? '',
                $appointment->starts_at,
            );

            $schedule = Schedule::thankYouSchedule([
                'id' => $appointment->id,
                'startsAt' => $appointment->starts_at,
                'createdAt' => $appointment->created_at,
            ], $config);

            $result = self::createTask([
                'patient' => $patient,
                'templateKey' => 'THANK_YOU',
                'sourceType' => 'APPOINTMENT',
                'sourceId' => $appointment->id,
                'language' => $context['language'],
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'THANK_YOU', $context['language']),
                    $context,
                ),
                'sourceStatus' => Appointment::STATUS_COMPLETED,
                'dueAt' => $schedule['dueAt'],
                'sendBy' => $schedule['sendBy'],
                'expiresAt' => $schedule['expiresAt'],
            ]);

            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The follow-up reminder for every follow-up that is due soon.
     *
     * @param  array<string, mixed>  $config
     */
    public static function generateFollowUps(\DateTimeInterface $now, array $config): int
    {
        $tz = $config['timeZone'];
        $todayKey = ClinicTime::clinicDateKey($now, $tz);
        $horizon = ClinicTime::combineDateAndTime(
            ClinicTime::dateKeyAdd($todayKey, $config['followupLeadDays'] + 1),
            '00:00',
            $tz,
        );

        $followUps = FollowUp::query()
            ->with('patient')
            ->where('status', FollowUp::STATUS_OPEN)
            ->where('due_date', '<', $horizon)
            ->take(500)
            ->get();

        $bodies = MsgQueries::loadTemplateBodies();
        $created = 0;

        foreach ($followUps as $followUp) {
            $patient = self::patientPayload($followUp->patient);
            $language = Render::resolveLanguage($patient['preferredLanguage']);

            $context = [
                'patientName' => $patient['fullName'],
                'language' => $language,
                'clinicName' => (string) Settings::get('clinic_name', ''),
                'reviewLink' => (string) Settings::get('review_link', ''),
                'timeZone' => $tz,
            ];

            $schedule = Schedule::followUpSchedule($followUp->due_date, $config, $now);

            $result = self::createTask([
                'patient' => $patient,
                'templateKey' => 'FOLLOWUP_DUE',
                'sourceType' => 'FOLLOWUP',
                'sourceId' => $followUp->id,
                'language' => $language,
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'FOLLOWUP_DUE', $language),
                    $context,
                ),
                'sourceStatus' => FollowUp::STATUS_OPEN,
                'dueAt' => $schedule['dueAt'],
                'sendBy' => $schedule['sendBy'],
                'expiresAt' => $schedule['expiresAt'],
            ]);

            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The recall for every patient who has not been seen for `recallMonths`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function generateRecalls(\DateTimeInterface $now, array $config): int
    {
        $tz = $config['timeZone'];
        $period = Schedule::recallPeriodKey($now, $tz);
        $threshold = (new \DateTimeImmutable('@'.$now->getTimestamp()))
            ->modify('-'.$config['recallMonths'].' months');

        $patients = Patient::query()
            ->where('do_not_message', false)
            ->where('active', true)
            ->take(1000)
            ->get();

        $bodies = MsgQueries::loadTemplateBodies();
        $created = 0;

        foreach ($patients as $patient) {
            // A patient with an upcoming appointment is not a recall candidate.
            $upcoming = Appointment::query()
                ->where('patient_id', $patient->id)
                ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
                ->where('starts_at', '>', $now)
                ->count();

            if ($upcoming > 0) {
                continue;
            }

            $lastAppointment = Appointment::query()
                ->where('patient_id', $patient->id)
                ->where('status', Appointment::STATUS_COMPLETED)
                ->orderByDesc('starts_at')
                ->first();

            $lastSale = Sale::query()
                ->where('patient_id', $patient->id)
                ->where('status', Sale::STATUS_CONFIRMED)
                ->orderByDesc('sale_date')
                ->first();

            $lastActivity = null;
            foreach ([$lastAppointment?->starts_at, $lastSale?->sale_date] as $candidate) {
                if ($candidate === null) {
                    continue;
                }

                if ($lastActivity === null || $candidate->getTimestamp() > $lastActivity->getTimestamp()) {
                    $lastActivity = $candidate;
                }
            }

            if ($lastActivity === null || $lastActivity->getTimestamp() > $threshold->getTimestamp()) {
                continue;
            }

            $payload = self::patientPayload($patient);
            $language = Render::resolveLanguage($payload['preferredLanguage']);

            $context = [
                'patientName' => $payload['fullName'],
                'language' => $language,
                'clinicName' => (string) Settings::get('clinic_name', ''),
                'reviewLink' => (string) Settings::get('review_link', ''),
                'timeZone' => $tz,
            ];

            $schedule = Schedule::recallSchedule($now);

            $result = self::createTask([
                'patient' => $payload,
                'templateKey' => 'RECALL',
                'sourceType' => 'RECALL',
                'sourceId' => $patient->id.'#'.$period,
                'language' => $language,
                'renderedMessage' => Render::renderMessage(
                    Render::bodyFor($bodies, 'RECALL', $language),
                    $context,
                ),
                'dueAt' => $schedule['dueAt'],
                'sendBy' => $schedule['sendBy'],
                'expiresAt' => $schedule['expiresAt'],
            ]);

            if ($result === 'created') {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The patient fields the messaging rules need.
     *
     * @return array<string, mixed>
     */
    private static function patientPayload(?Patient $patient): array
    {
        return [
            'id' => $patient?->id ?? '',
            'fullName' => (string) ($patient?->full_name ?? ''),
            'phoneE164' => (string) ($patient?->phone_e164 ?? ''),
            'preferredLanguage' => (string) ($patient?->preferred_language ?? 'en'),
            'doNotMessage' => (bool) ($patient?->do_not_message ?? false),
            'active' => (bool) ($patient?->active ?? false),
        ];
    }

    /**
     * The render context of one appointment.
     *
     * @param  array<string, mixed>  $patient
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function appointmentContext(
        array $patient,
        array $config,
        string $doctorName,
        ?\DateTimeInterface $startsAt,
    ): array {
        return [
            'patientName' => $patient['fullName'],
            'language' => Render::resolveLanguage($patient['preferredLanguage']),
            'clinicName' => (string) Settings::get('clinic_name', ''),
            'reviewLink' => (string) Settings::get('review_link', ''),
            'startsAt' => $startsAt,
            'doctorName' => $doctorName,
            'timeZone' => $config['timeZone'],
        ];
    }

    /** Escapes the LIKE wildcards in a source-id prefix. */
    private static function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix).'%';
    }
}
