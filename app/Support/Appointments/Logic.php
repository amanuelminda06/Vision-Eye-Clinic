<?php

declare(strict_types=1);

namespace App\Support\Appointments;

use App\Support\ClinicTime;
use App\Support\Hr\Shifts;

/**
 * Appointment geometry (pure — unit tested), ported 1:1 from
 * src/modules/appointments/logic.ts.
 *
 * Status transitions, the double-booking warning, shift/leave coverage
 * advisories, time-window slots and the follow-up due-date presets.
 */
final class Logic
{
    /** Lawful non-terminal moves. */
    private const BASE_TRANSITIONS = [
        'SCHEDULED' => ['CONFIRMED', 'CANCELLED', 'NO_SHOW'],
        'CONFIRMED' => ['COMPLETED', 'CANCELLED', 'NO_SHOW'],
        'COMPLETED' => [],
        'NO_SHOW' => [],
        'CANCELLED' => [],
    ];

    /** @var list<string> Terminal states are frozen for everyone. */
    public const TERMINAL = ['COMPLETED', 'NO_SHOW', 'CANCELLED'];

    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Whether `from -> to` is allowed. Terminal states are frozen for everyone;
     * moving out of them (a correction) requires the OWNER (`ownerOverride`)
     * and is always audited by the action.
     *
     * @param  array{ownerOverride?: bool}  $opts
     */
    public static function transitionAllowed(string $from, string $to, array $opts = []): bool
    {
        if ($from === $to) {
            return false;
        }

        if (! Constants::isAppointmentStatus($from) || ! Constants::isAppointmentStatus($to)) {
            return false;
        }

        if (self::isTerminalStatus($from)) {
            return ($opts['ownerOverride'] ?? false) === true;
        }

        return in_array($to, self::BASE_TRANSITIONS[$from], true);
    }

    /** SCHEDULED and CONFIRMED are the "active" statuses. */
    public static function isActiveStatus(string $status): bool
    {
        return $status === 'SCHEDULED' || $status === 'CONFIRMED';
    }

    /**
     * Overlapping active appointments for the same doctor — the double-booking
     * warning.
     *
     * @param  list<array{id: string, doctorId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface, status: string}>  $existing
     * @param  array{id?: string|null, doctorId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}  $candidate
     * @return list<array<string, mixed>>
     */
    public static function findDoctorDoubleBookings(array $existing, array $candidate): array
    {
        $clashes = [];

        foreach ($existing as $appointment) {
            if (isset($candidate['id']) && $appointment['id'] === $candidate['id']) {
                continue;
            }

            if ($appointment['doctorId'] !== $candidate['doctorId']) {
                continue;
            }

            if (! self::isActiveStatus($appointment['status'])) {
                continue;
            }

            if ($appointment['startsAt']->getTimestamp() < $candidate['endsAt']->getTimestamp()
                && $candidate['startsAt']->getTimestamp() < $appointment['endsAt']->getTimestamp()) {
                $clashes[] = $appointment;
            }
        }

        return $clashes;
    }

    /**
     * True when the candidate window sits fully inside one of the doctor's
     * shifts (overnight shifts work because both instants are UTC).
     *
     * @param  list<array{startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}>  $shifts
     */
    public static function isInsideShift(array $shifts, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): bool
    {
        foreach ($shifts as $shift) {
            if ($shift['startsAt']->getTimestamp() <= $startsAt->getTimestamp()
                && $shift['endsAt']->getTimestamp() >= $endsAt->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when an APPROVED leave covers the clinic-local day.
     *
     * @param  array{status: string, startDate: mixed, endDate: mixed}  $leave
     */
    public static function leaveCoversDateKey(array $leave, string $dateKey): bool
    {
        if ($leave['status'] !== 'APPROVED') {
            return false;
        }

        $startKey = Shifts::dateColumnToKey($leave['startDate']);
        $endKey = Shifts::dateColumnToKey($leave['endDate']);

        return $dateKey >= $startKey && $dateKey <= $endKey;
    }

    /**
     * Non-blocking scheduling advisories: the appointment window is not fully
     * inside one of the doctor's shifts and/or falls on approved-leave days.
     * Returns a list of stable keys (`outsideShift`, `onLeave`) — never throws.
     *
     * @param  array{doctorId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}  $candidate
     * @param  list<array{staffId?: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}>  $shifts
     * @param  list<array{staffId: string, status: string, startDate: mixed, endDate: mixed}>  $leaves
     * @return list<string>
     */
    public static function appointmentCoverageWarnings(array $candidate, array $shifts, array $leaves, string $timeZone = ClinicTime::DEFAULT_TIMEZONE): array
    {
        $warnings = [];

        if (! self::isInsideShift($shifts, $candidate['startsAt'], $candidate['endsAt'])) {
            $warnings[] = 'outsideShift';
        }

        $startKey = ClinicTime::clinicDateKey($candidate['startsAt'], $timeZone);
        $endKey = ClinicTime::clinicDateKey($candidate['endsAt'], $timeZone);

        $onLeave = false;

        foreach (Shifts::dateKeysBetween($startKey, $endKey, 30) as $dateKey) {
            foreach ($leaves as $leave) {
                if ($leave['staffId'] === $candidate['doctorId'] && self::leaveCoversDateKey($leave, $dateKey)) {
                    $onLeave = true;
                    break 2;
                }
            }
        }

        if ($onLeave) {
            $warnings[] = 'onLeave';
        }

        return $warnings;
    }

    /**
     * Best-effort match of a website request's phone to an existing patient.
     *
     * @param  list<array{id: string, phoneE164: string}>  $patients
     * @return array{id: string, phoneE164: string}|null
     */
    public static function resolvePatientMatch(array $patients, string $phoneE164): ?array
    {
        foreach ($patients as $patient) {
            if ($patient['phoneE164'] === $phoneE164) {
                return $patient;
            }
        }

        return null;
    }

    /**
     * Wall-clock `{start,end}` (HH:mm) for a request's preferred time window.
     *
     * @return array{start: string, end: string}
     */
    public static function slotForTimeWindow(?string $window): array
    {
        $key = strtolower(trim((string) $window));

        foreach (Constants::TIME_WINDOWS as $slot) {
            if ($slot['key'] === $key) {
                return ['start' => $slot['start'], 'end' => $slot['end']];
            }
        }

        return ['start' => Constants::TIME_WINDOWS[0]['start'], 'end' => Constants::TIME_WINDOWS[0]['end']];
    }

    /**
     * UTC instants for a clinic-local day/start + duration in minutes.
     *
     * @return array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}
     */
    public static function appointmentInstants(string $dateKey, string $startTime, int $durationMin, string $timeZone = ClinicTime::DEFAULT_TIMEZONE): array
    {
        $startsAt = ClinicTime::combineDateAndTime($dateKey, $startTime, $timeZone);
        $endsAt = (new \DateTimeImmutable('@'.($startsAt->getTimestamp() + max(1, $durationMin) * 60)))
            ->setTimezone(new \DateTimeZone('UTC'));

        return ['startsAt' => $startsAt, 'endsAt' => $endsAt];
    }

    /** Calendar-month add, clamped to the last day of the target month. */
    public static function dateKeyAddMonths(string $baseKey, int $months): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $baseKey));

        $target = new \DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00Z', $y, $m), new \DateTimeZone('UTC'));
        $target = $target->modify(sprintf('%+d months', $months));
        $lastDay = (int) $target->modify('last day of this month')->format('j');
        $day = min($d, $lastDay);

        return $target->format('Y-m-').str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * `YYYY-MM-DD` due date for a follow-up preset (2 weeks / 1/3/6 months /
     * custom).
     */
    public static function followUpDueKey(string $baseKey, string $preset, ?string $customKey = null): string
    {
        return match ($preset) {
            '2w' => ClinicTime::dateKeyAdd($baseKey, 14),
            '1m' => self::dateKeyAddMonths($baseKey, 1),
            '3m' => self::dateKeyAddMonths($baseKey, 3),
            '6m' => self::dateKeyAddMonths($baseKey, 6),
            'custom' => $customKey !== null && $customKey !== '' ? substr($customKey, 0, 10) : $baseKey,
            default => $baseKey,
        };
    }

    /**
     * Clinic working minutes between two instants, computed from the settings
     * working-hours grid (indexed by weekday, 0 = Sunday). Closed days/hours
     * don't count; the iteration is capped to bound work on absurdly old
     * windows.
     *
     * @param  list<array{open: string, close: string, closed: bool}|null>  $hours
     */
    public static function workingMinutesBetween(\DateTimeInterface $from, \DateTimeInterface $to, array $hours, string $timeZone = ClinicTime::DEFAULT_TIMEZONE): int
    {
        if ($from->getTimestamp() >= $to->getTimestamp()) {
            return 0;
        }

        $total = 0;
        $cursor = $from->getTimestamp();
        $guard = 0;

        while ($cursor < $to->getTimestamp() && $guard < 400) {
            $dayIndex = self::dayOfWeek($cursor, $timeZone);
            $day = $hours[$dayIndex] ?? null;

            if ($day !== null && ! $day['closed']) {
                $dayKey = ClinicTime::clinicDateKey(self::instant($cursor), $timeZone);
                $openAt = ClinicTime::combineDateAndTime($dayKey, $day['open'], $timeZone)->getTimestamp();
                $closeAt = ClinicTime::combineDateAndTime($dayKey, $day['close'], $timeZone)->getTimestamp();

                $windowStart = max($from->getTimestamp(), $openAt);
                $windowEnd = min($to->getTimestamp(), $closeAt);

                if ($windowEnd > $windowStart) {
                    $total += $windowEnd - $windowStart;
                }
            }

            $nextKey = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(self::instant($cursor), $timeZone), 1);
            $cursor = ClinicTime::combineDateAndTime($nextKey, '00:00', $timeZone)->getTimestamp();
            $guard++;
        }

        return (int) floor($total / 60);
    }

    /** Weekday (0 = Sunday) of an instant in the clinic timezone. */
    private static function dayOfWeek(int $timestamp, string $timeZone): int
    {
        $key = ClinicTime::clinicDateKey(self::instant($timestamp), $timeZone);

        return (int) (new \DateTimeImmutable($key.'T00:00:00Z'))->format('w');
    }

    /** A UTC instant from a Unix timestamp. */
    private static function instant(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
