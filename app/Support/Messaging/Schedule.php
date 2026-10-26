<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Support\ClinicTime;

/**
 * Pure dueTime/sendBy/expiresAt math for every automatic template, ported
 * from src/modules/messaging/schedule.ts.
 *
 * All wall-clock values are interpreted in the clinic timezone and stored as
 * UTC instants.
 */
final class Schedule
{
    /**
     * One day in SECONDS (the legacy used milliseconds, but PHP timestamps
     * are seconds).
     */
    public const DAY_SECONDS = 86400;

    /** @var array{timeZone: string, reminderSendTime: string, reminderSendByOffsetMinutes: int, followupLeadDays: int, recallMonths: int} */
    public const DEFAULT_MESSAGING_SETTINGS = [
        'timeZone' => 'Africa/Addis_Ababa',
        'reminderSendTime' => '14:00',
        'reminderSendByOffsetMinutes' => 60,
        'followupLeadDays' => 3,
        'recallMonths' => 12,
    ];

    /** The later of two instants (ties → the first). */
    public static function laterOf(\DateTimeInterface $a, \DateTimeInterface $b): \DateTimeInterface
    {
        return $a->getTimestamp() >= $b->getTimestamp() ? $a : $b;
    }

    /**
     * Appointment confirmation: due now, must go out by the end of the day.
     *
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function appointmentConfirmationSchedule(\DateTimeInterface $now, array $settings): array
    {
        return [
            'dueAt' => self::instant($now),
            'sendBy' => ClinicTime::endOfDay($now, (string) $settings['timeZone']),
            'expiresAt' => null,
        ];
    }

    /**
     * Appointment reminder: the day before at `reminderSendTime`, expiring at
     * the appointment start. Late bookings fall due immediately. Returns null
     * when the appointment has already started.
     *
     * @param  array{id: string, startsAt: \DateTimeInterface, createdAt: \DateTimeInterface}  $appointment
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}|null
     */
    public static function appointmentReminderSchedule(array $appointment, array $settings, \DateTimeInterface $now): ?array
    {
        if ($appointment['startsAt']->getTimestamp() <= $now->getTimestamp()) {
            return null;
        }

        $tz = (string) $settings['timeZone'];
        $dueDayKey = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey($appointment['startsAt'], $tz), -1);
        $dueAt = ClinicTime::combineDateAndTime($dueDayKey, (string) $settings['reminderSendTime'], $tz);

        if ($appointment['createdAt']->getTimestamp() > $dueAt->getTimestamp()
            || $dueAt->getTimestamp() < $now->getTimestamp()) {
            $dueAt = self::instant($now);
        }

        $offset = max(0, (int) $settings['reminderSendByOffsetMinutes']);
        $sendBy = self::instant($appointment['startsAt']->getTimestamp() - $offset * 60);

        return [
            'dueAt' => $dueAt,
            'sendBy' => $sendBy,
            'expiresAt' => self::instant($appointment['startsAt']->getTimestamp()),
        ];
    }

    /**
     * Morning-of reminder: due 08:00 clinic time, expires at the appointment.
     *
     * @param  array{id: string, startsAt: \DateTimeInterface, createdAt: \DateTimeInterface}  $appointment
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}|null
     */
    public static function appointmentSameDaySchedule(array $appointment, array $settings, \DateTimeInterface $now): ?array
    {
        if ($appointment['startsAt']->getTimestamp() <= $now->getTimestamp()) {
            return null;
        }

        $tz = (string) $settings['timeZone'];
        $todayKey = ClinicTime::clinicDateKey($now, $tz);
        $dueAt = ClinicTime::combineDateAndTime($todayKey, '08:00', $tz);

        if ($dueAt->getTimestamp() < $now->getTimestamp()) {
            $dueAt = self::instant($now);
        }

        return [
            'dueAt' => $dueAt,
            'sendBy' => self::instant($appointment['startsAt']->getTimestamp()),
            'expiresAt' => self::instant($appointment['startsAt']->getTimestamp()),
        ];
    }

    /**
     * No-show follow-up: next morning 09:00, expiring after three days.
     *
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function noShowSchedule(\DateTimeInterface $now, array $settings): array
    {
        $tz = (string) $settings['timeZone'];
        $dueKey = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey($now, $tz), 1);
        $dueAt = ClinicTime::combineDateAndTime($dueKey, '09:00', $tz);

        return [
            'dueAt' => $dueAt,
            'sendBy' => null,
            'expiresAt' => self::instant($dueAt->getTimestamp() + 3 * self::DAY_SECONDS),
        ];
    }

    /**
     * Follow-up reminder: `followupLeadDays` before the due date, expires +7d.
     *
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function followUpSchedule(\DateTimeInterface $dueDate, array $settings, \DateTimeInterface $now): array
    {
        $tz = (string) $settings['timeZone'];
        $dueKey = ClinicTime::clinicDateKey($dueDate, $tz);
        $startKey = ClinicTime::dateKeyAdd($dueKey, -(int) $settings['followupLeadDays']);
        $dueAt = ClinicTime::combineDateAndTime($startKey, '09:00', $tz);

        if ($dueAt->getTimestamp() < $now->getTimestamp()) {
            $dueAt = self::instant($now);
        }

        return [
            'dueAt' => $dueAt,
            'sendBy' => null,
            'expiresAt' => ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dueKey, 7), '23:59', $tz),
        ];
    }

    /**
     * Thank-you: the morning after a completed appointment (09:00).
     *
     * @param  array{id: string, startsAt: \DateTimeInterface, createdAt: \DateTimeInterface}  $appointment
     * @param  array<string, mixed>  $settings
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function thankYouSchedule(array $appointment, array $settings): array
    {
        $tz = (string) $settings['timeZone'];
        $dayKey = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey($appointment['startsAt'], $tz), 1);

        return [
            'dueAt' => ClinicTime::combineDateAndTime($dayKey, '09:00', $tz),
            'sendBy' => null,
            'expiresAt' => null,
        ];
    }

    /**
     * Recall: due immediately, valid for 30 days.
     *
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function recallSchedule(\DateTimeInterface $now): array
    {
        return [
            'dueAt' => self::instant($now),
            'sendBy' => null,
            'expiresAt' => self::instant($now->getTimestamp() + 30 * self::DAY_SECONDS),
        ];
    }

    /**
     * Immediate task (welcome, schedule change, manual): due now.
     *
     * @return array{dueAt: \DateTimeImmutable, sendBy: \DateTimeImmutable|null, expiresAt: \DateTimeImmutable|null}
     */
    public static function immediateSchedule(\DateTimeInterface $now): array
    {
        return [
            'dueAt' => self::instant($now),
            'sendBy' => null,
            'expiresAt' => null,
        ];
    }

    /** `YYYY-MM` period key used to make monthly recall generation idempotent. */
    public static function recallPeriodKey(\DateTimeInterface $now, string $timeZone): string
    {
        return substr(ClinicTime::clinicDateKey($now, $timeZone), 0, 7);
    }

    /** A UTC instant from any date-time-ish value. */
    private static function instant(int|\DateTimeInterface $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return (new \DateTimeImmutable('@'.$value->getTimestamp()))
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('UTC'));
    }
}
