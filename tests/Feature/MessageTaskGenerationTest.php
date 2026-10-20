<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Staff;
use App\Support\Messaging\TaskGenerator;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The message-task generation: every automatic template, its timing in
 * Africa/Addis_Ababa, the idempotency, the reschedule/cancel rules and the
 * eligibility re-check.
 */
class MessageTaskGenerationTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    private int $phoneCounter = 0;

    /** The pinned "now" used by every timing rule: Oct 8 2026, 12:00 local. */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-08T09:00:00Z', new \DateTimeZone('UTC'));
    }

    private function doctor(): Staff
    {
        return Staff::factory()->create([
            'is_doctor' => true,
            'active' => true,
            'full_name' => 'Dr. Abebe Kebede',
        ]);
    }

    private function patient(array $overrides = []): Patient
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        return Patient::factory()->create(array_merge([
            'created_by' => $owner->id,
            'full_name' => 'Zewdibu Ermias',
            // The phone column is unique — every patient gets its own.
            'phone_e164' => '+251911'.str_pad((string) (++$this->phoneCounter), 6, '0', STR_PAD_LEFT),
            'preferred_language' => 'en',
            'do_not_message' => false,
            'active' => true,
        ], $overrides));
    }

    private function appointment(array $overrides = []): Appointment
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // `created_at` is not fillable, so it is set explicitly — the
        // "booked late" rule depends on it.
        $createdAt = $overrides['created_at'] ?? '2026-10-01 00:00:00';
        unset($overrides['created_at']);

        $appointment = Appointment::create(array_merge([
            'patient_id' => $this->patient()->id,
            'doctor_id' => $this->doctor()->id,
            'starts_at' => '2026-10-13 06:00:00',   // 09:00 Addis
            'ends_at' => '2026-10-13 06:30:00',
            'status' => Appointment::STATUS_SCHEDULED,
            'created_from' => 'STAFF',
            'created_by' => $owner->id,
        ], $overrides));

        $appointment->forceFill(['created_at' => $createdAt])->save();

        return $appointment->fresh();
    }

    // ---------------------------------------------------------------------
    // Appointment confirmation / reminder / same-day
    // ---------------------------------------------------------------------

    public function test_a_scheduled_appointment_prepares_a_day_before_reminder(): void
    {
        $appointment = $this->appointment();

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        // Due the day before at the reminder time (14:00 local = 11:00Z).
        $this->assertSame('2026-10-12 11:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        // Send-by = start − 60 min.
        $this->assertSame('2026-10-13 05:00:00', $reminder->send_by->format('Y-m-d H:i:s'));
        // Expires at the start.
        $this->assertSame('2026-10-13 06:00:00', $reminder->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(MessageTask::STATUS_PENDING, (string) $reminder->status);
    }

    public function test_a_reminder_booked_late_is_due_immediately(): void
    {
        // Booked on Oct 12 at 12:00Z, i.e. AFTER the 14:00 local due time
        // (11:00Z) of the same day.
        $appointment = $this->appointment(['created_at' => '2026-10-12 12:00:00']);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertSame(
            $this->now()->getTimestamp(),
            $reminder->due_at->getTimestamp(),
            'A late booking must be due immediately, not in the past.',
        );
    }

    public function test_the_reminder_uses_the_configured_send_time(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'reminder_send_time'],
            ['value' => '09:30'],
        );

        $appointment = $this->appointment();

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        // 09:30 local = 06:30Z.
        $this->assertSame('2026-10-12 06:30:00', $reminder->due_at->format('Y-m-d H:i:s'));
    }

    public function test_the_reminder_send_by_uses_the_configured_offset(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'reminder_send_by_offset_minutes'],
            ['value' => 120],
        );

        $appointment = $this->appointment();

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertSame('2026-10-13 04:00:00', $reminder->send_by->format('Y-m-d H:i:s'));
    }

    public function test_a_started_appointment_gets_no_reminder(): void
    {
        $appointment = $this->appointment(['starts_at' => '2026-10-08 05:00:00']);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertSame(0, MessageTask::query()->where('template_key', 'APPT_REMINDER')->count());
    }

    public function test_a_confirmed_appointment_also_gets_a_confirmation(): void
    {
        $appointment = $this->appointment(['status' => Appointment::STATUS_CONFIRMED]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $confirmation = MessageTask::query()->where('template_key', 'APPT_CONFIRMATION')->sole();

        // Due immediately, send-by = the end of the clinic day, never expires.
        $this->assertNotNull($confirmation->due_at);
        $this->assertNull($confirmation->expires_at);
        $this->assertSame(
            '2026-10-08 21:00:00',
            $confirmation->send_by->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_scheduled_appointment_gets_no_confirmation(): void
    {
        $appointment = $this->appointment(['status' => Appointment::STATUS_SCHEDULED]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertSame(0, MessageTask::query()->where('template_key', 'APPT_CONFIRMATION')->count());
    }

    public function test_the_same_day_reminder_is_due_at_eight_local(): void
    {
        // "Now" is 06:00 local, so the 08:00 local slot is still ahead.
        $now = new \DateTimeImmutable('2026-10-08T03:00:00Z', new \DateTimeZone('UTC'));

        // Today, 17:00 local.
        $appointment = $this->appointment(['starts_at' => '2026-10-08 14:00:00']);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $now);

        $sameDay = MessageTask::query()->where('template_key', 'APPT_SAME_DAY')->sole();

        // 08:00 local = 05:00Z.
        $this->assertSame('2026-10-08 05:00:00', $sameDay->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 14:00:00', $sameDay->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_passed_same_day_slot_is_due_immediately(): void
    {
        // "Now" is 12:00 local — the 08:00 local slot has passed.
        $appointment = $this->appointment(['starts_at' => '2026-10-08 14:00:00']);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $sameDay = MessageTask::query()->where('template_key', 'APPT_SAME_DAY')->sole();

        $this->assertSame(
            $this->now()->getTimestamp(),
            $sameDay->due_at->getTimestamp(),
            'A passed 08:00 slot must be due immediately, not in the past.',
        );
    }

    // ---------------------------------------------------------------------
    // Idempotency, reschedule, cancel / complete / no-show
    // ---------------------------------------------------------------------

    public function test_generation_is_idempotent(): void
    {
        $appointment = $this->appointment(['status' => Appointment::STATUS_CONFIRMED]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());
        $first = MessageTask::query()->count();

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertSame($first, MessageTask::query()->count());
        $this->assertGreaterThan(0, $first);
    }

    public function test_rescheduling_cancels_the_old_reminder_and_creates_a_new_one(): void
    {
        $appointment = $this->appointment(['status' => Appointment::STATUS_CONFIRMED]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $old = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();
        $this->assertSame(MessageTask::STATUS_PENDING, (string) $old->status);

        // The appointment moves to Oct 14.
        $appointment->forceFill(['starts_at' => '2026-10-14 06:00:00'])->save();
        TaskGenerator::onAppointmentChanged(
            $appointment->id,
            new \DateTimeImmutable('2026-10-13 06:00:00Z'),
            $this->now(),
        );

        $old->refresh();
        $this->assertSame(MessageTask::STATUS_CANCELLED, (string) $old->status);

        $reminders = MessageTask::query()->where('template_key', 'APPT_REMINDER')->get();

        $this->assertCount(2, $reminders);
        $this->assertSame(1, $reminders->where('status', MessageTask::STATUS_PENDING)->count());

        // The new one is versioned by the new start time.
        $new = $reminders->where('status', MessageTask::STATUS_PENDING)->first();
        $this->assertNotSame($old->source_id, $new->source_id);
        $this->assertSame('2026-10-13 11:00:00', $new->due_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelling_the_appointment_cancels_the_pending_reminders(): void
    {
        $appointment = $this->appointment(['status' => Appointment::STATUS_CONFIRMED]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());
        $this->assertGreaterThan(0, MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count());

        $appointment->forceFill(['status' => Appointment::STATUS_CANCELLED])->save();
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertSame(
            0,
            MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count(),
        );
    }

    public function test_completing_the_appointment_cancels_the_reminders_and_prepares_a_thank_you(): void
    {
        $appointment = $this->appointment([
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => '2026-10-08 14:00:00',
        ]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $appointment->forceFill(['status' => Appointment::STATUS_COMPLETED])->save();
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        // The schedule messages are cancelled…
        $this->assertSame(
            0,
            MessageTask::query()
                ->whereIn('template_key', TaskGenerator::APPOINTMENT_SCHEDULE_KEYS)
                ->whereIn('status', TaskGenerator::OPEN_STATUSES)
                ->count(),
        );

        // …and the thank-you is prepared.
        $thankYou = MessageTask::query()->where('template_key', 'THANK_YOU')->sole();

        // The morning after the appointment, 09:00 local = 06:00Z on Oct 9.
        $this->assertSame('2026-10-09 06:00:00', $thankYou->due_at->format('Y-m-d H:i:s'));
    }

    public function test_a_no_show_cancels_the_reminders_and_prepares_the_no_show_message(): void
    {
        $appointment = $this->appointment([
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => '2026-10-08 14:00:00',
        ]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $appointment->forceFill(['status' => Appointment::STATUS_NO_SHOW])->save();
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertSame(
            0,
            MessageTask::query()
                ->whereIn('template_key', TaskGenerator::APPOINTMENT_SCHEDULE_KEYS)
                ->whereIn('status', TaskGenerator::OPEN_STATUSES)
                ->count(),
        );

        $noShow = MessageTask::query()->where('template_key', 'NO_SHOW')->sole();

        // The next morning 09:00 local = 06:00Z, expiring 72 h later.
        $this->assertSame('2026-10-09 06:00:00', $noShow->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 06:00:00', $noShow->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_the_schedule_change_task_is_immediate_and_always_new(): void
    {
        $appointment = $this->appointment();

        $this->assertSame('created', TaskGenerator::generateScheduleChangeTask($appointment->id, $this->now()));

        // Every click makes a new row (the timestamp is in the source id).
        usleep(1100000);
        $this->assertSame('created', TaskGenerator::generateScheduleChangeTask($appointment->id, $this->now()));

        $this->assertSame(2, MessageTask::query()->where('template_key', 'SCHEDULE_CHANGE')->count());
        $this->assertSame('missing', TaskGenerator::generateScheduleChangeTask('does-not-exist', $this->now()));
    }

    // ---------------------------------------------------------------------
    // Follow-up, birthday, recall, welcome
    // ---------------------------------------------------------------------

    public function test_an_open_follow_up_prepares_a_reminder(): void
    {
        $followUp = FollowUp::create([
            'patient_id' => $this->patient()->id,
            'due_date' => '2026-10-20',
            'status' => FollowUp::STATUS_OPEN,
            'created_by' => $this->createUser(['role' => Roles::OWNER])->id,
        ]);

        TaskGenerator::onFollowUpChanged($followUp->id, $this->now());

        $task = MessageTask::query()->where('template_key', 'FOLLOWUP_DUE')->sole();

        // The lead time is 3 days: Oct 20 − 3 = Oct 17, 09:00 local = 06:00Z.
        $this->assertSame('2026-10-17 06:00:00', $task->due_at->format('Y-m-d H:i:s'));
        // Expires 7 days after the due date at 23:59 local = 20:59Z.
        $this->assertSame('2026-10-27 20:59:00', $task->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_closing_a_follow_up_cancels_its_reminder(): void
    {
        $followUp = FollowUp::create([
            'patient_id' => $this->patient()->id,
            'due_date' => '2026-10-20',
            'status' => FollowUp::STATUS_OPEN,
            'created_by' => $this->createUser(['role' => Roles::OWNER])->id,
        ]);

        TaskGenerator::onFollowUpChanged($followUp->id, $this->now());
        $this->assertSame(1, MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count());

        $followUp->forceFill(['status' => FollowUp::STATUS_DONE])->save();
        TaskGenerator::onFollowUpChanged($followUp->id, $this->now());

        $this->assertSame(0, MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count());
    }

    public function test_the_birthday_message_is_due_at_eight_local_today(): void
    {
        $this->patient(['birth_date' => '1990-10-08']);

        $created = TaskGenerator::generateBirthdays($this->now(), TaskGenerator::settings());

        $this->assertSame(1, $created);

        $task = MessageTask::query()->where('template_key', 'BIRTHDAY')->sole();

        $this->assertSame('2026-10-08 05:00:00', $task->due_at->format('Y-m-d H:i:s'));
        // Expires at the next local midnight.
        $this->assertSame('2026-10-08 21:00:00', $task->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_birthday_that_is_not_today_is_not_prepared(): void
    {
        $this->patient(['birth_date' => '1990-10-09']);

        $this->assertSame(0, TaskGenerator::generateBirthdays($this->now(), TaskGenerator::settings()));
    }

    public function test_the_recall_skips_a_patient_with_an_upcoming_appointment(): void
    {
        $patient = $this->patient();
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // A completed appointment 13 months ago → a recall candidate.
        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor()->id,
            'starts_at' => '2025-08-01 06:00:00',
            'ends_at' => '2025-08-01 06:30:00',
            'status' => Appointment::STATUS_COMPLETED,
            'created_from' => 'STAFF',
            'created_by' => $owner->id,
        ]);

        $this->assertSame(1, TaskGenerator::generateRecalls($this->now(), TaskGenerator::settings()));

        // With an upcoming appointment the patient is not a recall candidate.
        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor()->id,
            'starts_at' => '2026-11-01 06:00:00',
            'ends_at' => '2026-11-01 06:30:00',
            'status' => Appointment::STATUS_CONFIRMED,
            'created_from' => 'STAFF',
            'created_by' => $owner->id,
        ]);

        $this->assertSame(0, TaskGenerator::generateRecalls($this->now(), TaskGenerator::settings()));
    }

    public function test_the_recall_needs_a_long_enough_gap(): void
    {
        $patient = $this->patient();
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // A completed appointment 3 months ago → too recent.
        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor()->id,
            'starts_at' => '2026-07-01 06:00:00',
            'ends_at' => '2026-07-01 06:30:00',
            'status' => Appointment::STATUS_COMPLETED,
            'created_from' => 'STAFF',
            'created_by' => $owner->id,
        ]);

        $this->assertSame(0, TaskGenerator::generateRecalls($this->now(), TaskGenerator::settings()));
    }

    public function test_the_recall_uses_the_sale_date_too(): void
    {
        $patient = $this->patient();
        $owner = $this->createUser(['role' => Roles::OWNER]);

        Sale::create([
            'receipt_number' => '2025-000001',
            'patient_id' => $patient->id,
            'sale_date' => '2025-06-01 06:00:00',
            'status' => Sale::STATUS_CONFIRMED,
            'payment_method' => 'CASH',
            'total' => '100.00',
            'created_by' => $owner->id,
            'confirmed_at' => '2025-06-01 06:00:00',
        ]);

        $this->assertSame(1, TaskGenerator::generateRecalls($this->now(), TaskGenerator::settings()));
    }

    public function test_the_welcome_message_is_prepared_when_consent_is_given(): void
    {
        $patient = $this->patient(['do_not_message' => false]);

        TaskGenerator::onPatientConsentGiven($patient->id, $this->now());

        $task = MessageTask::query()->where('template_key', 'WELCOME')->sole();

        $this->assertNotNull($task->due_at);
        $this->assertSame(MessageTask::STATUS_PENDING, (string) $task->status);
    }

    // ---------------------------------------------------------------------
    // Eligibility
    // ---------------------------------------------------------------------

    public function test_a_do_not_message_patient_gets_a_blocked_task(): void
    {
        $patient = $this->patient(['do_not_message' => true]);

        $appointment = $this->appointment(['patient_id' => $patient->id]);
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertSame(MessageTask::STATUS_BLOCKED, (string) $reminder->status);
        $this->assertSame('doNotMessage', $reminder->blocked_reason);
    }

    public function test_an_inactive_patient_gets_a_blocked_task(): void
    {
        $patient = $this->patient(['active' => false]);

        $appointment = $this->appointment(['patient_id' => $patient->id]);
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertSame(MessageTask::STATUS_BLOCKED, (string) $reminder->status);
        $this->assertSame('inactive', $reminder->blocked_reason);
    }

    public function test_a_patient_without_a_valid_phone_gets_a_blocked_task(): void
    {
        $patient = $this->patient(['phone_e164' => '']);

        $appointment = $this->appointment(['patient_id' => $patient->id]);
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertSame(MessageTask::STATUS_BLOCKED, (string) $reminder->status);
        $this->assertSame('invalidPhone', $reminder->blocked_reason);
    }

    public function test_opting_out_cancels_every_pending_message(): void
    {
        $patient = $this->patient();
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $appointment = $this->appointment(['patient_id' => $patient->id]);
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $this->assertGreaterThan(0, MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count());

        $this->actingAs($owner)->post("/patients/{$patient->id}/do-not-message", [
            'stop' => 'true',
            'reason' => 'Patient asked',
        ])->assertRedirect();

        $this->assertSame(0, MessageTask::query()->whereIn('status', TaskGenerator::OPEN_STATUSES)->count());
    }

    // ---------------------------------------------------------------------
    // The job
    // ---------------------------------------------------------------------

    public function test_the_job_expires_the_overdue_tasks(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment(['patient_id' => $patient->id]);
        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        // Push the expiry into the past.
        $reminder->forceFill(['expires_at' => '2020-01-01 00:00:00'])->save();

        $summary = TaskGenerator::runMessageJobs($this->now());

        $this->assertSame(1, $summary['expired']);
        $reminder->refresh();
        $this->assertSame(MessageTask::STATUS_EXPIRED, (string) $reminder->status);
    }

    public function test_the_job_prepares_the_same_day_messages(): void
    {
        $patient = $this->patient();

        // An appointment later today.
        $this->appointment([
            'patient_id' => $patient->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => '2026-10-08 14:00:00',   // 17:00 local
            'created_at' => '2026-10-01 00:00:00',
        ]);

        $summary = TaskGenerator::runMessageJobs($this->now());

        $this->assertGreaterThanOrEqual(1, $summary['sameDay']);
    }

    public function test_the_job_is_safe_to_run_twice(): void
    {
        $patient = $this->patient();
        $this->appointment([
            'patient_id' => $patient->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'starts_at' => '2026-10-08 14:00:00',
            'created_at' => '2026-10-01 00:00:00',
        ]);

        TaskGenerator::runMessageJobs($this->now());
        $first = MessageTask::query()->count();

        TaskGenerator::runMessageJobs($this->now());

        $this->assertSame($first, MessageTask::query()->count());
    }

    public function test_the_rendered_message_replaces_the_variables(): void
    {
        // The templates must exist for the body to be rendered.
        $this->seed(DatabaseSeeder::class);

        $patient = $this->patient();
        $appointment = $this->appointment([
            'patient_id' => $patient->id,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        TaskGenerator::onAppointmentChanged($appointment->id, null, $this->now());

        $reminder = MessageTask::query()->where('template_key', 'APPT_REMINDER')->sole();

        $this->assertStringContainsString('Zewdibu Ermias', $reminder->rendered_message);
        $this->assertStringContainsString('Vision Eye Clinic', $reminder->rendered_message);
        $this->assertStringNotContainsString('{name}', $reminder->rendered_message);
        $this->assertStringNotContainsString('{clinic}', $reminder->rendered_message);
    }
}
