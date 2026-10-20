<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Support\ClinicTime;

/**
 * Shift geometry (pure — unit tested), ported from src/modules/hr/shifts.ts.
 *
 * Shifts are UTC instants and may cross midnight, so overlap and generation
 * always work on instants — never on wall-clock strings alone. Day keys are
 * used for bucketing and display only.
 */
final class Shifts
{
    /**
     * True when two half-open intervals [aStart, aEnd) and [bStart, bEnd)
     * overlap. Touching edges do NOT overlap.
     */
    public static function shiftsOverlap(
        \DateTimeInterface $aStart,
        \DateTimeInterface $aEnd,
        \DateTimeInterface $bStart,
        \DateTimeInterface $bEnd,
    ): bool {
        return $aStart->getTimestamp() < $bEnd->getTimestamp()
            && $bStart->getTimestamp() < $aEnd->getTimestamp();
    }

    /** A shift is valid only when it is strictly longer than zero. */
    public static function isValidShift(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return $start->getTimestamp() < $end->getTimestamp();
    }

    /**
     * First existing shift that overlaps the candidate (same staff member).
     *
     * @param  list<array{id: string, staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}>  $existing
     * @param  array{staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface, ignoreId?: string|null}  $candidate
     * @return array{id: string, staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}|null
     */
    public static function findOverlappingShift(array $existing, array $candidate): ?array
    {
        $ignoreId = $candidate['ignoreId'] ?? null;

        foreach ($existing as $shift) {
            if ($shift['id'] === $ignoreId) {
                continue;
            }

            if ($shift['staffId'] !== $candidate['staffId']) {
                continue;
            }

            if (self::shiftsOverlap($shift['startsAt'], $shift['endsAt'], $candidate['startsAt'], $candidate['endsAt'])) {
                return $shift;
            }
        }

        return null;
    }

    /** True when the shift is currently running (`at` in [startsAt, endsAt)). */
    public static function isShiftActiveAt(array $shift, \DateTimeInterface $at): bool
    {
        return $at->getTimestamp() >= $shift['startsAt']->getTimestamp()
            && $at->getTimestamp() < $shift['endsAt']->getTimestamp();
    }

    /**
     * Earliest shift starting at/after `at` (null when none).
     *
     * @param  list<array{id: string, staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}>  $shifts
     * @return array{id: string, staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}|null
     */
    public static function nextShiftAfter(array $shifts, \DateTimeInterface $at): ?array
    {
        $next = null;

        foreach ($shifts as $shift) {
            if ($shift['startsAt']->getTimestamp() < $at->getTimestamp()) {
                continue;
            }

            if ($next === null || $shift['startsAt']->getTimestamp() < $next['startsAt']->getTimestamp()) {
                $next = $shift;
            }
        }

        return $next;
    }

    /** Weekday (0 = Sunday) of a `YYYY-MM-DD` key — timezone-independent. */
    public static function weekdayOf(string $dateKey): int
    {
        $parts = explode('-', $dateKey);
        $y = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 1);
        $d = (int) ($parts[2] ?? 1);

        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-%02dT00:00:00Z', $y, $m, $d)))->format('w');
    }

    /**
     * Normalises a `@db.Date` column value (UTC midnight) to a `YYYY-MM-DD` key.
     */
    public static function dateColumnToKey(mixed $value): string
    {
        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return '';
    }

    /** A shift is overnight when the end time is not after the start time. */
    public static function isOvernight(string $startTime, string $endTime): bool
    {
        return $endTime <= $startTime;
    }

    /**
     * Concrete instants for a template on one calendar day (clinic local).
     *
     * @param  array{startTime: string, endTime: string}  $template
     * @return array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}
     */
    public static function shiftInstantsForDay(array $template, string $dateKey, string $timeZone = ClinicTime::DEFAULT_TIMEZONE): array
    {
        $startsAt = ClinicTime::combineDateAndTime($dateKey, $template['startTime'], $timeZone);

        $endKey = self::isOvernight($template['startTime'], $template['endTime'])
            ? ClinicTime::dateKeyAdd($dateKey, 1)
            : $dateKey;

        $endsAt = ClinicTime::combineDateAndTime($endKey, $template['endTime'], $timeZone);

        return ['startsAt' => $startsAt, 'endsAt' => $endsAt];
    }

    /**
     * True when the template applies on the given calendar day (weekday match
     * plus the inclusive validity window).
     *
     * @param  array{weekday: int, validFrom: mixed, validTo: mixed}  $template
     */
    public static function templateAppliesOn(array $template, string $dateKey): bool
    {
        if (self::weekdayOf($dateKey) !== (int) $template['weekday']) {
            return false;
        }

        $from = self::dateColumnToKey($template['validFrom']);
        $to = $template['validTo'] === null ? null : self::dateColumnToKey($template['validTo']);

        return $dateKey >= $from && ($to === null || $dateKey <= $to);
    }

    /**
     * Inclusive list of date keys, capped at `maxDays + 1` entries.
     *
     * @return list<string>
     */
    public static function dateKeysBetween(string $fromKey, string $toKey, int $maxDays = 400): array
    {
        $keys = [];
        $cursor = $fromKey;

        for ($i = 0; $i <= $maxDays; $i++) {
            $keys[] = $cursor;

            if ($cursor === $toKey) {
                break;
            }

            $cursor = ClinicTime::dateKeyAdd($cursor, 1);
        }

        return $keys;
    }

    /**
     * Expands weekly templates into concrete shifts across `[fromKey, toKey]`
     * (clinic-local calendar days). Overnight templates end on the next day.
     * Pure: no DB, no clock — the caller supplies the date range.
     *
     * @param  list<array{id: string, staffId: string, weekday: int, startTime: string, endTime: string, validFrom: mixed, validTo: mixed}>  $templates
     * @return list<array{templateId: string, staffId: string, dateKey: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    public static function generateTemplateShifts(
        array $templates,
        string $fromKey,
        string $toKey,
        string $timeZone = ClinicTime::DEFAULT_TIMEZONE,
        int $maxDays = 400,
    ): array {
        $generated = [];

        foreach (self::dateKeysBetween($fromKey, $toKey, $maxDays) as $dateKey) {
            foreach ($templates as $template) {
                if (! self::templateAppliesOn($template, $dateKey)) {
                    continue;
                }

                $instants = self::shiftInstantsForDay($template, $dateKey, $timeZone);

                $generated[] = [
                    'templateId' => $template['id'],
                    'staffId' => $template['staffId'],
                    'dateKey' => $dateKey,
                    'startsAt' => $instants['startsAt'],
                    'endsAt' => $instants['endsAt'],
                ];
            }
        }

        usort($generated, fn (array $a, array $b): int => $a['startsAt']->getTimestamp() <=> $b['startsAt']->getTimestamp());

        return $generated;
    }

    /** Monday-based start of the week containing `dateKey`. */
    public static function weekStartKey(string $dateKey): string
    {
        $sinceMonday = (self::weekdayOf($dateKey) + 6) % 7;

        return ClinicTime::dateKeyAdd($dateKey, -$sinceMonday);
    }

    /**
     * Builds a 7-day key list starting at `startKey`.
     *
     * @return list<string>
     */
    public static function weekDays(string $startKey): array
    {
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = ClinicTime::dateKeyAdd($startKey, $i);
        }

        return $days;
    }

    /**
     * Shifts a concrete shift by whole days — used by "copy last week".
     *
     * @param  array{staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface, note?: string|null}  $shift
     * @return array{staffId: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, note: string|null}
     */
    public static function shiftByDays(array $shift, int $days): array
    {
        $delta = $days * 86400;

        return [
            'staffId' => $shift['staffId'],
            'startsAt' => (new \DateTimeImmutable('@'.($shift['startsAt']->getTimestamp() + $delta)))->setTimezone(new \DateTimeZone('UTC')),
            'endsAt' => (new \DateTimeImmutable('@'.($shift['endsAt']->getTimestamp() + $delta)))->setTimezone(new \DateTimeZone('UTC')),
            'note' => $shift['note'] ?? null,
        ];
    }

    /** `YYYY-MM-DD` day key for an instant in the clinic timezone. */
    public static function dayKeyOf(\DateTimeInterface $input, string $timeZone): string
    {
        return ClinicTime::clinicDateKey($input, $timeZone);
    }
}
