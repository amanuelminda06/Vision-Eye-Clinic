<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Date/time helpers (rule 7): everything is stored in UTC and computed in the
 * clinic timezone (default Africa/Addis_Ababa, from settings). Never use the
 * server's local timezone.
 *
 * The public API is a verbatim port of the legacy `src/lib/datetime.ts` —
 * every function takes an explicit `$tz` (settings-editable) and formats are
 * hand-rolled because the target host (and this box) has no ext-intl.
 * Ground truth for formatInTz was captured from node v20 (full ICU) and is
 * asserted in tests/ClinicTimeTest.php.
 */
final class ClinicTime
{
    /** Legacy DEFAULT_TIMEZONE — also the settings default for clinic timezone. */
    public const DEFAULT_TIMEZONE = 'Africa/Addis_Ababa';

    /** Alias kept for callers written against the earlier port. */
    public const DISPLAY_TIMEZONE = self::DEFAULT_TIMEZONE;

    /** All storage is UTC. */
    public const APP_TIMEZONE = 'UTC';

    /** @var list<string> */
    private const WEEKDAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** @var list<string> */
    private const WEEKDAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** @var list<string> */
    private const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var list<string> */
    private const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /*
    |--------------------------------------------------------------------------
    | Conveniences kept from the earlier port (used by helpers / MessageBadge)
    |--------------------------------------------------------------------------
    */

    /** "now" in the clinic display timezone. */
    public static function now(): DateTimeImmutable
    {
        return self::nowUtc()->setTimezone(new DateTimeZone(self::DEFAULT_TIMEZONE));
    }

    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::APP_TIMEZONE));
    }

    /** UTC storage string for "now", e.g. "2026-10-20 11:30:00". */
    public static function utcNowSql(): string
    {
        return self::nowUtc()->format('Y-m-d H:i:s');
    }

    /*
    |--------------------------------------------------------------------------
    | Verbatim legacy datetime.ts API
    |--------------------------------------------------------------------------
    */

    /** Offset (ms) of `$tz` at the given instant: local-as-UTC minus true UTC. */
    public static function tzOffsetMs(string|DateTimeInterface $input, string $tz = self::DEFAULT_TIMEZONE): int
    {
        return self::offsetMsAt(self::parseInput($input)->getTimestamp(), $tz);
    }

    /** 'YYYY-MM-DD' of the instant in the given timezone. */
    public static function clinicDateKey(string|DateTimeInterface $input, string $tz = self::DEFAULT_TIMEZONE): string
    {
        $p = self::tzParts(self::parseInput($input), $tz);

        return sprintf('%04d-%02d-%02d', $p['year'], $p['month'], $p['day']);
    }

    /** 'HH:mm' of the instant in the given timezone. */
    public static function clinicTimeKey(string|DateTimeInterface $input, string $tz = self::DEFAULT_TIMEZONE): string
    {
        $p = self::tzParts(self::parseInput($input), $tz);

        return sprintf('%02d:%02d', $p['hour'], $p['minute']);
    }

    /**
     * Wall-clock date + time in a timezone → the UTC instant.
     * Two-pass offset refinement across DST edges (exactly as legacy).
     *
     * @throws InvalidArgumentException on malformed keys (legacy `Invalid date/time: …`)
     */
    public static function combineDateAndTime(string $dateKey, string $timeKey, string $tz = self::DEFAULT_TIMEZONE): DateTimeImmutable
    {
        // JS: unlimited split + first-three destructure (trailing parts ignored;
        // missing parts become undefined → NaN → throw below).
        $dateParts = explode('-', $dateKey);
        $timeParts = explode(':', $timeKey);
        $y = self::jsNumber($dateParts[0] ?? '');
        $m = array_key_exists(1, $dateParts) ? self::jsNumber($dateParts[1]) : NAN;
        $d = array_key_exists(2, $dateParts) ? self::jsNumber($dateParts[2]) : NAN;
        $hh = array_key_exists(0, $timeParts) ? self::jsNumber($timeParts[0]) : NAN;
        $mm = array_key_exists(1, $timeParts) ? self::jsNumber($timeParts[1]) : NAN;

        foreach ([$y, $m, $d, $hh, $mm] as $n) {
            if (! is_finite($n) || floor($n) !== $n) {
                throw new InvalidArgumentException("Invalid date/time: {$dateKey} {$timeKey}");
            }
        }

        // JS: Date.UTC(y, (m || 1) - 1, d || 1, hh || 0, mm || 0) — 0 falls back,
        // and out-of-range parts normalise (gmmktime matches Date.UTC here).
        $wall = gmmktime(
            (int) ($hh ?: 0),
            (int) ($mm ?: 0),
            0,
            (int) ($m ?: 1),
            (int) ($d ?: 1),
            (int) $y,
        );

        $offset = self::offsetMsAt($wall, $tz);
        $utc = $wall - intdiv($offset, 1000);

        // Refine across DST edges: re-read the offset at the resolved instant.
        $offset2 = self::offsetMsAt($utc, $tz);
        if ($offset2 !== $offset) {
            $utc = $wall - intdiv($offset2, 1000);
        }

        return (new DateTimeImmutable('@'.$utc))->setTimezone(new DateTimeZone(self::APP_TIMEZONE));
    }

    /** UTC instant of local midnight for the day containing `$input`. */
    public static function startOfDay(string|DateTimeInterface $input, string $tz = self::DEFAULT_TIMEZONE): DateTimeImmutable
    {
        return self::combineDateAndTime(self::clinicDateKey($input, $tz), '00:00', $tz);
    }

    /** Exclusive end of the local day containing `$input` (local midnight next day). */
    public static function endOfDay(string|DateTimeInterface $input, string $tz = self::DEFAULT_TIMEZONE): DateTimeImmutable
    {
        return self::combineDateAndTime(self::dateKeyAdd(self::clinicDateKey($input, $tz), 1), '00:00', $tz);
    }

    /** Pure calendar-day arithmetic on a 'YYYY-MM-DD' key (UTC calendar, no tz). */
    public static function dateKeyAdd(string $dateKey, int $days): string
    {
        $parts = explode('-', $dateKey);
        $y = self::jsNumber($parts[0] ?? '');
        $m = array_key_exists(1, $parts) ? self::jsNumber($parts[1]) : NAN;
        $d = array_key_exists(2, $parts) ? self::jsNumber($parts[2]) : NAN;

        foreach ([$y, $m, $d] as $n) {
            if (! is_finite($n) || floor($n) !== $n) {
                throw new InvalidArgumentException("Invalid date key: {$dateKey}");
            }
        }

        // gmmktime normalises overflows exactly like Date.UTC(y, m - 1, d + days).
        return gmdate('Y-m-d', gmmktime(0, 0, 0, (int) $m, (int) $d + $days, (int) $y));
    }

    /**
     * Format an instant in the clinic timezone for display.
     *
     * Hand-rolled Intl.DateTimeFormat('en-GB') equivalent — see the class doc
     * and tests/ClinicTimeTest.php for the captured ground truth. $locale is
     * accepted for API parity; output is always the en-GB English pattern
     * (no caller passes a locale, and the host has no ext-intl).
     *
     * @param  array<string, mixed>|null  $options  null → {dateStyle: medium, timeStyle: short}
     *
     * @throws InvalidArgumentException on unknown style values (legacy threw RangeError)
     */
    public static function formatInTz(
        string|DateTimeInterface $input,
        string $tz = self::DEFAULT_TIMEZONE,
        ?array $options = null,
        string $locale = 'en-GB',
    ): string {
        $instant = self::parseInput($input);
        $options ??= ['dateStyle' => 'medium', 'timeStyle' => 'short'];

        $hasStyle = array_key_exists('dateStyle', $options) || array_key_exists('timeStyle', $options);

        if ($hasStyle || $options === []) {
            return self::formatStyled($instant, $tz, $options);
        }

        return self::formatFields($instant, $tz, $options);
    }

    /**
     * Format a date range (both @db.Date, UTC midnight) in the clinic timezone.
     * Same instant or same UTC calendar day → a single date; else "A → B".
     */
    public static function formatDateRange(
        string|DateTimeInterface $startDate,
        string|DateTimeInterface $endDate,
        string $tz = self::DEFAULT_TIMEZONE,
        string $locale = 'en-GB',
    ): string {
        $start = self::parseInput($startDate);
        $end = self::parseInput($endDate);

        // Legacy: same instant OR same *UTC* calendar day (getUTCFullYear/Month/
        // Date on the raw parsed instants — not the tz-converted local date).
        $startUtc = $start->setTimezone(new DateTimeZone(self::APP_TIMEZONE));
        $endUtc = $end->setTimezone(new DateTimeZone(self::APP_TIMEZONE));
        $same = $start->getTimestamp() === $end->getTimestamp()
            || $startUtc->format('Y-m-d') === $endUtc->format('Y-m-d');

        $fmt = fn (DateTimeImmutable $d): string => self::formatInTz($d, $tz, ['dateStyle' => 'medium'], $locale);

        return $same ? $fmt($start) : $fmt($start).' → '.$fmt($end);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * JS `new Date(input)` semantics: date-only → UTC midnight; naive datetime
     * → UTC (legacy servers ran TZ=UTC); offset/Z respected; Date passthrough.
     */
    private static function parseInput(string|DateTimeInterface $input): DateTimeImmutable
    {
        if ($input instanceof DateTimeImmutable) {
            return $input;
        }

        if ($input instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($input);
        }

        $s = trim($input);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return new DateTimeImmutable($s.' 00:00:00', new DateTimeZone(self::APP_TIMEZONE));
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)$/', $s, $m) === 1) {
            $datetime = $m[1].'T'.$m[2];

            return new DateTimeImmutable($datetime, new DateTimeZone(self::APP_TIMEZONE));
        }

        try {
            return new DateTimeImmutable($input, new DateTimeZone(self::APP_TIMEZONE));
        } catch (\Exception) {
            throw new InvalidArgumentException("Invalid date: {$input}");
        }
    }

    /**
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: int, weekday: int}
     */
    private static function tzParts(DateTimeImmutable $instant, string $tz): array
    {
        $local = $instant->setTimezone(new DateTimeZone($tz));

        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
            'day' => (int) $local->format('j'),
            'hour' => (int) $local->format('G'),
            'minute' => (int) $local->format('i'),
            'second' => (int) $local->format('s'),
            'weekday' => (int) $local->format('w'),
        ];
    }

    /** Offset (ms) of `$tz` at the given UTC epoch second. */
    private static function offsetMsAt(int $utcTimestamp, string $tz): int
    {
        $local = (new DateTimeImmutable('@'.$utcTimestamp))->setTimezone(new DateTimeZone($tz));

        // Local wall clock read as if it were UTC, minus the true UTC instant.
        $asUtc = gmmktime(
            (int) $local->format('G'),
            (int) $local->format('i'),
            (int) $local->format('s'),
            (int) $local->format('n'),
            (int) $local->format('j'),
            (int) $local->format('Y'),
        );

        return ($asUtc - $utcTimestamp) * 1000;
    }

    /**
     * JS `Number(raw)` for date-key parts: '' → 0, numeric strings → float,
     * everything else (NaN) → NAN.
     */
    private static function jsNumber(string $raw): float
    {
        $s = trim($raw);

        if ($s === '') {
            return 0.0;
        }

        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $s) === 1) {
            return (float) $s;
        }

        return NAN;
    }

    /** styled mode: dateStyle/timeStyle (defaults: medium / short, en-GB). */
    private static function formatStyled(DateTimeImmutable $instant, string $tz, array $options): string
    {
        $date = '';
        $time = '';

        $dateStyle = $options['dateStyle'] ?? 'short';
        $timeStyle = $options['timeStyle'] ?? null;

        // Bare Intl.DateTimeFormat('en-GB') / {} → the locale's default pattern
        // (short date, no time). Only reachable when options are empty.
        if ($options === []) {
            $dateStyle = 'short';
        }

        if (array_key_exists('dateStyle', $options) || $options === []) {
            $p = self::tzParts($instant, $tz);
            $date = match ($dateStyle) {
                'short' => sprintf('%02d/%02d/%04d', $p['day'], $p['month'], $p['year']),
                'medium' => sprintf('%d %s %d', $p['day'], self::MONTHS_SHORT[$p['month'] - 1], $p['year']),
                'long' => sprintf('%d %s %d', $p['day'], self::MONTHS_LONG[$p['month'] - 1], $p['year']),
                'full' => self::WEEKDAYS_LONG[$p['weekday']].', '.sprintf('%d %s %d', $p['day'], self::MONTHS_LONG[$p['month'] - 1], $p['year']),
                default => throw new InvalidArgumentException('Unknown dateStyle: '.var_export($dateStyle, true)),
            };
        }

        if ($timeStyle !== null) {
            $p = self::tzParts($instant, $tz);
            $clock = match ($timeStyle) {
                'short' => sprintf('%02d:%02d', $p['hour'], $p['minute']),
                'medium' => sprintf('%02d:%02d:%02d', $p['hour'], $p['minute'], $p['second']),
                'long', 'full' => sprintf('%02d:%02d:%02d', $p['hour'], $p['minute'], $p['second']).' '.self::tzAbbreviation(self::offsetMsAt($instant->getTimestamp(), $tz)),
                default => throw new InvalidArgumentException('Unknown timeStyle: '.var_export($timeStyle, true)),
            };
            $time = $clock;
        }

        if ($date !== '' && $time !== '') {
            return $date.', '.$time;
        }

        return $date !== '' ? $date : $time;
    }

    /**
     * field mode: explicit Intl option fields. en-GB pattern order wins over
     * the option object's key order — ICU formats `weekday, day, month, year`
     * then `hour[:minute[:second]]`, regardless of how the options were listed.
     */
    private static function formatFields(DateTimeImmutable $instant, string $tz, array $options): string
    {
        $p = self::tzParts($instant, $tz);

        $hour12 = isset($options['hour12']) && $options['hour12'] === true;
        $hasMinute = array_key_exists('minute', $options);
        $hasSecond = array_key_exists('second', $options);

        // Date fields, rendered in en-GB order: weekday, day, month, year.
        $dateParts = [];

        if (array_key_exists('weekday', $options)) {
            $dateParts[] = match ($options['weekday']) {
                'short' => self::WEEKDAYS_SHORT[$p['weekday']],
                'long' => self::WEEKDAYS_LONG[$p['weekday']],
                default => throw new InvalidArgumentException('Unknown weekday: '.var_export($options['weekday'], true)),
            };
        }

        if (array_key_exists('day', $options)) {
            $dateParts[] = match ($options['day']) {
                'numeric' => (string) $p['day'],
                '2-digit' => sprintf('%02d', $p['day']),
                default => throw new InvalidArgumentException('Unknown day: '.var_export($options['day'], true)),
            };
        }

        if (array_key_exists('month', $options)) {
            $dateParts[] = match ($options['month']) {
                'short' => self::MONTHS_SHORT[$p['month'] - 1],
                'long' => self::MONTHS_LONG[$p['month'] - 1],
                'numeric', '2-digit' => sprintf('%02d', $p['month']),
                default => throw new InvalidArgumentException('Unknown month: '.var_export($options['month'], true)),
            };
        }

        if (array_key_exists('year', $options)) {
            $dateParts[] = match ($options['year']) {
                'numeric' => (string) $p['year'],
                '2-digit' => sprintf('%02d', $p['year'] % 100),
                default => throw new InvalidArgumentException('Unknown year: '.var_export($options['year'], true)),
            };
        }

        // Time fields in order: hour, minute, second (+ day period).
        $timeParts = [];
        $dayPeriod = null;

        if (array_key_exists('hour', $options)) {
            if ($hour12) {
                // ICU en-GB h12 (captured from node): hour % 12 — 0 at noon and
                // midnight — with a lowercase day period.
                $timeParts[] = (string) ($p['hour'] % 12);
                $dayPeriod = $p['hour'] < 12 ? 'am' : 'pm';
            } else {
                // ICU quirk captured from node: hour 'numeric' is zero-padded
                // standalone, unpadded when minute/second join the pattern.
                $padded = $options['hour'] === '2-digit' || (! $hasMinute && ! $hasSecond);
                $timeParts[] = $padded ? sprintf('%02d', $p['hour']) : (string) $p['hour'];
            }
        }

        if (array_key_exists('minute', $options)) {
            $timeParts[] = match ($options['minute']) {
                'numeric' => (string) $p['minute'],
                '2-digit' => sprintf('%02d', $p['minute']),
                default => throw new InvalidArgumentException('Unknown minute: '.var_export($options['minute'], true)),
            };
        }

        if (array_key_exists('second', $options)) {
            $timeParts[] = match ($options['second']) {
                'numeric' => (string) $p['second'],
                '2-digit' => sprintf('%02d', $p['second']),
                default => throw new InvalidArgumentException('Unknown second: '.var_export($options['second'], true)),
            };
        }

        $date = implode(' ', $dateParts);
        $time = implode(':', $timeParts);

        if ($dayPeriod !== null && $time !== '') {
            $time .= ' '.$dayPeriod;
        }

        if (array_key_exists('timeZoneName', $options)) {
            $abbr = self::tzAbbreviation(self::offsetMsAt($instant->getTimestamp(), $tz));

            if ($date === '' && $time === '') {
                // ICU: timeZoneName alone → the locale's default pattern + name.
                return self::formatStyled($instant, $tz, ['dateStyle' => 'short']).', '.$abbr;
            }

            $time = $time === '' ? $abbr : $time.' '.$abbr;
        }

        if ($date !== '' && $time !== '') {
            return $date.', '.$time;
        }

        return $date !== '' ? $date : $time;
    }

    /** en-GB short zone name from a fixed offset: GMT, GMT+3, GMT+5:45, GMT-5. */
    private static function tzAbbreviation(int $offsetMs): string
    {
        if ($offsetMs === 0) {
            return 'GMT';
        }

        $sign = $offsetMs < 0 ? '-' : '+';
        $total = intdiv(abs($offsetMs), 60_000);
        $hours = intdiv($total, 60);
        $minutes = $total % 60;

        return 'GMT'.$sign.$hours.($minutes > 0 ? ':'.sprintf('%02d', $minutes) : '');
    }
}
