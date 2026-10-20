<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Support\ClinicTime;

/**
 * Leave geometry (pure — unit tested), ported from src/modules/hr/leave.ts.
 *
 * Leave is stored as inclusive calendar dates (clinic local), so overlap and
 * balances compare date keys. A half day counts 0.5 days.
 */
final class Leave
{
    /** Inclusive day span between two `YYYY-MM-DD` keys (always >= 1). */
    public static function countCalendarDays(string $startKey, string $endKey): int
    {
        $from = self::parseKey($startKey);
        $to = self::parseKey($endKey);

        if ($from === null || $to === null || $to < $from) {
            return 1;
        }

        return (int) round(($to - $from) / 86400) + 1;
    }

    /**
     * Requested days for a request: inclusive span, minus 0.5 for a half day.
     *
     * @param  array{startDate: mixed, endDate: mixed, halfDay: bool}  $request
     */
    public static function requestedDays(array $request): float
    {
        $days = self::countCalendarDays(
            Shifts::dateColumnToKey($request['startDate']),
            Shifts::dateColumnToKey($request['endDate']),
        );

        return $request['halfDay'] ? max(0.5, $days - 0.5) : (float) $days;
    }

    /** True when two inclusive date ranges intersect. */
    public static function leaveRangesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /**
     * True when an already existing request overlaps `[startKey, endKey]`.
     *
     * @param  list<array{id: string, staffId: string, type: string, status: string, startDate: mixed, endDate: mixed, halfDay: bool}>  $existing
     * @param  array{staffId: string, startDate: mixed, endDate: mixed, ignoreId?: string|null}  $candidate
     * @return array<string, mixed>|null
     */
    public static function findOverlappingLeave(array $existing, array $candidate): ?array
    {
        $startKey = Shifts::dateColumnToKey($candidate['startDate']);
        $endKey = Shifts::dateColumnToKey($candidate['endDate']);
        $ignoreId = $candidate['ignoreId'] ?? null;

        foreach ($existing as $leave) {
            if ($leave['id'] === $ignoreId) {
                continue;
            }

            if ($leave['staffId'] !== $candidate['staffId']) {
                continue;
            }

            if (self::leaveRangesOverlap(
                $startKey,
                $endKey,
                Shifts::dateColumnToKey($leave['startDate']),
                Shifts::dateColumnToKey($leave['endDate']),
            )) {
                return $leave;
            }
        }

        return null;
    }

    /**
     * True when an approved leave covers `dateKey` (inclusive).
     *
     * @param  array{status: string, startDate: mixed, endDate: mixed}  $leave
     */
    public static function leaveCoversDate(array $leave, string $dateKey): bool
    {
        if ($leave['status'] !== 'APPROVED') {
            return false;
        }

        $startKey = Shifts::dateColumnToKey($leave['startDate']);
        $endKey = Shifts::dateColumnToKey($leave['endDate']);

        return $dateKey >= $startKey && $dateKey <= $endKey;
    }

    /**
     * The staff member is on approved leave at `dateKey`.
     *
     * @param  list<array<string, mixed>>  $leaves
     */
    public static function isOnLeaveAt(array $leaves, string $staffId, string $dateKey): bool
    {
        foreach ($leaves as $leave) {
            if ($leave['staffId'] === $staffId && self::leaveCoversDate($leave, $dateKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Annual-leave balance for a calendar year. `taken` counts APPROVED ANNUAL
     * requests that START inside the year (half days count 0.5).
     *
     * @param  list<array<string, mixed>>  $leaves
     * @return array{entitlement: int|null, taken: float, remaining: int|null}
     */
    public static function leaveBalance(?int $annualLeaveDays, array $leaves, int $year): array
    {
        $taken = 0.0;

        foreach ($leaves as $leave) {
            if ($leave['type'] !== 'ANNUAL' || $leave['status'] !== 'APPROVED') {
                continue;
            }

            if (substr(Shifts::dateColumnToKey($leave['startDate']), 0, 4) !== (string) $year) {
                continue;
            }

            $taken += self::requestedDays($leave);
        }

        return [
            'entitlement' => $annualLeaveDays,
            'taken' => $taken,
            'remaining' => $annualLeaveDays === null ? null : $annualLeaveDays - $taken,
        ];
    }

    /**
     * Inclusive `{fromKey, toKey}` for a request.
     *
     * @param  array{startDate: mixed, endDate: mixed}  $request
     * @return array{fromKey: string, toKey: string}
     */
    public static function leaveRange(array $request): array
    {
        return [
            'fromKey' => Shifts::dateColumnToKey($request['startDate']),
            'toKey' => Shifts::dateColumnToKey($request['endDate']),
        ];
    }

    /** Date key helper for "today" in the clinic timezone. */
    public static function todayKey(\DateTimeInterface $now, string $timeZone): string
    {
        return ClinicTime::clinicDateKey($now, $timeZone);
    }

    /** UTC-midnight timestamp of a date key, or null when malformed. */
    private static function parseKey(string $key): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $key.' 00:00:00', new \DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }
}
