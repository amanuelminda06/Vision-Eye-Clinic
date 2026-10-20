<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ClinicTime as T;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/datetime.test.ts + src/lib/datetime-extra.test.ts (legacy
 * gold standard), extended with the node-v20/ICU ground truth captured for
 * every formatInTz option combo the app and specs actually use. The target
 * host has no ext-intl, so formatInTz is hand-rolled — these fixtures are the
 * contract that keeps it byte-compatible with the legacy Intl output.
 */
class ClinicTimeTest extends TestCase
{
    private const ADDIS = 'Africa/Addis_Ababa';

    private const NY = 'America/New_York';

    private static function iso(DateTimeImmutable $d): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $d->getTimestamp());
    }

    public function test_keeps_a_constant_3h_offset(): void
    {
        $this->assertSame(3 * 3600 * 1000, T::tzOffsetMs('2026-01-15T12:00:00Z', self::ADDIS));
        $this->assertSame(3 * 3600 * 1000, T::tzOffsetMs('2026-07-15T12:00:00Z', self::ADDIS));
    }

    public function test_derives_the_local_date_key_from_a_utc_instant(): void
    {
        $this->assertSame('2026-10-08', T::clinicDateKey('2026-10-08T20:59:00Z', self::ADDIS)); // 23:59 local
        $this->assertSame('2026-10-09', T::clinicDateKey('2026-10-08T21:00:00Z', self::ADDIS)); // 00:00 local
        $this->assertSame('2026-10-08', T::clinicDateKey('2026-10-08T23:30:00Z', 'UTC'));
    }

    public function test_derives_the_local_time_key(): void
    {
        $this->assertSame('08:30', T::clinicTimeKey('2026-10-08T05:30:00Z', self::ADDIS));
        $this->assertSame('00:15', T::clinicTimeKey('2026-10-08T21:15:00Z', self::ADDIS));
    }

    public function test_converts_local_wall_time_to_the_utc_instant(): void
    {
        $this->assertSame('2026-10-08T05:30:00.000Z', self::iso(T::combineDateAndTime('2026-10-08', '08:30', self::ADDIS)));
        $this->assertSame('2026-10-07T21:00:00.000Z', self::iso(T::combineDateAndTime('2026-10-08', '00:00', self::ADDIS)));
    }

    public function test_computes_local_start_and_end_of_day_as_utc_instants(): void
    {
        $this->assertSame('2026-10-07T21:00:00.000Z', self::iso(T::startOfDay('2026-10-08T10:00:00Z', self::ADDIS)));
        $this->assertSame('2026-10-08T21:00:00.000Z', self::iso(T::endOfDay('2026-10-08T10:00:00Z', self::ADDIS)));
    }

    public function test_calendar_day_arithmetic_across_month_and_year_boundaries(): void
    {
        $this->assertSame('2027-01-01', T::dateKeyAdd('2026-12-31', 1));
        $this->assertSame('2026-02-28', T::dateKeyAdd('2026-03-01', -1));
        $this->assertSame('2024-02-29', T::dateKeyAdd('2024-02-28', 1));
    }

    public function test_formats_time_fields_in_the_target_timezone(): void
    {
        $hourMinute = ['hour' => '2-digit', 'minute' => '2-digit'];
        $this->assertSame('08:30', T::formatInTz('2026-10-08T05:30:00Z', self::ADDIS, $hourMinute));
        $this->assertSame('00:30', T::formatInTz('2026-10-08T21:30:00Z', self::ADDIS, $hourMinute));
    }

    public function test_rejects_malformed_date_and_time_keys(): void
    {
        $cases = [
            ['not-a-date', '12:00'],
            ['2026-10-12', 'oops'],
            ['', ''],
        ];

        foreach ($cases as [$dateKey, $timeKey]) {
            try {
                T::combineDateAndTime($dateKey, $timeKey, self::ADDIS);
                $this->fail("Expected throw for [{$dateKey}|{$timeKey}]");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("Invalid date/time: {$dateKey} {$timeKey}", $e->getMessage());
            }
        }
    }

    public function test_handles_a_dst_transition_by_refining_the_offset(): void
    {
        // 02:30 does not exist on 2026-03-08 in New York (spring forward).
        $dt = T::combineDateAndTime('2026-03-08', '02:30', self::NY);
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertContains(T::clinicTimeKey($dt, self::NY), ['01:30', '03:30']);
    }

    public function test_half_hour_timezone_offset(): void
    {
        // Kathmandu is UTC+5:45.
        $this->assertSame(5 * 3600000 + 45 * 60000, T::tzOffsetMs('2026-06-01T00:00:00Z', 'Asia/Kathmandu'));
    }

    /**
     * @return array<string, array{0: ?array<string, mixed>, 1: string, 2: string}>
     */
    public static function icuFixtures(): array
    {
        return [
            'dateStyle medium' => [['dateStyle' => 'medium'], '2026-10-12T09:00:00Z', '12 Oct 2026'],
            'dateStyle medium day 8' => [['dateStyle' => 'medium'], '2026-10-08T05:30:00Z', '8 Oct 2026'],
            'dateStyle medium next day' => [['dateStyle' => 'medium'], '2026-10-08T21:30:00Z', '9 Oct 2026'],
            'dateStyle medium 23:59' => [['dateStyle' => 'medium'], '2026-10-08T20:59:00Z', '8 Oct 2026'],
            'dateStyle medium january' => [['dateStyle' => 'medium'], '2026-01-15T12:00:00Z', '15 Jan 2026'],
            'dateStyle medium next year' => [['dateStyle' => 'medium'], '2026-12-31T21:00:00Z', '1 Jan 2027'],
            'medium+short noon' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-10-12T09:00:00Z', '12 Oct 2026, 12:00'],
            'medium+short morning' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-10-08T05:30:00Z', '8 Oct 2026, 08:30'],
            'medium+short after midnight' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-10-08T21:30:00Z', '9 Oct 2026, 00:30'],
            'medium+short end of day' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-10-08T20:59:00Z', '8 Oct 2026, 23:59'],
            'medium+short january' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-01-15T12:00:00Z', '15 Jan 2026, 15:00'],
            'medium+short new year' => [['dateStyle' => 'medium', 'timeStyle' => 'short'], '2026-12-31T21:00:00Z', '1 Jan 2027, 00:00'],
            'hourMinute noon' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-10-12T09:00:00Z', '12:00'],
            'hourMinute morning' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-10-08T05:30:00Z', '08:30'],
            'hourMinute after midnight' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-10-08T21:30:00Z', '00:30'],
            'hourMinute end of day' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-10-08T20:59:00Z', '23:59'],
            'hourMinute afternoon' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-01-15T12:00:00Z', '15:00'],
            'hourMinute midnight' => [['hour' => '2-digit', 'minute' => '2-digit'], '2026-12-31T21:00:00Z', '00:00'],
            'weekday day month' => [['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'], '2026-10-12T09:00:00Z', 'Mon 12 Oct'],
            'weekday day month morning' => [['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'], '2026-10-08T05:30:00Z', 'Thu 8 Oct'],
            'weekday day month next day' => [['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'], '2026-10-08T21:30:00Z', 'Fri 9 Oct'],
            'weekday day month january' => [['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'], '2026-01-15T12:00:00Z', 'Thu 15 Jan'],
            'weekday day month new year' => [['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'], '2026-12-31T21:00:00Z', 'Fri 1 Jan'],
            // appointments list: options written month-before-day — en-GB pattern order wins
            'list row noon' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-10-12T09:00:00Z', 'Mon 12 Oct, 12:00'],
            'list row morning' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-10-08T05:30:00Z', 'Thu 8 Oct, 8:30'],
            'list row after midnight' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-10-08T21:30:00Z', 'Fri 9 Oct, 0:30'],
            'list row end of day' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-10-08T20:59:00Z', 'Thu 8 Oct, 23:59'],
            'list row january' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-01-15T12:00:00Z', 'Thu 15 Jan, 15:00'],
            'list row new year' => [['weekday' => 'short', 'month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit'], '2026-12-31T21:00:00Z', 'Fri 1 Jan, 0:00'],
            'weekday day only' => [['weekday' => 'short', 'day' => 'numeric'], '2026-10-12T09:00:00Z', 'Mon 12'],
            'weekday day only morning' => [['weekday' => 'short', 'day' => 'numeric'], '2026-10-08T05:30:00Z', 'Thu 8'],
            'weekday day only new year' => [['weekday' => 'short', 'day' => 'numeric'], '2026-12-31T21:00:00Z', 'Fri 1'],
            'timeStyle short noon' => [['timeStyle' => 'short'], '2026-10-12T09:00:00Z', '12:00'],
            'timeStyle short morning' => [['timeStyle' => 'short'], '2026-10-08T05:30:00Z', '08:30'],
            'timeStyle short midnight' => [['timeStyle' => 'short'], '2026-12-31T21:00:00Z', '00:00'],
            'dateStyle long' => [['dateStyle' => 'long'], '2026-10-12T09:00:00Z', '12 October 2026'],
            'dateStyle long day 8' => [['dateStyle' => 'long'], '2026-10-08T05:30:00Z', '8 October 2026'],
            'dateStyle long new year' => [['dateStyle' => 'long'], '2026-12-31T21:00:00Z', '1 January 2027'],
            'dateStyle short' => [['dateStyle' => 'short'], '2026-10-12T09:00:00Z', '12/10/2026'],
            'dateStyle short padded' => [['dateStyle' => 'short'], '2026-10-08T05:30:00Z', '08/10/2026'],
            'dateStyle short new year' => [['dateStyle' => 'short'], '2026-12-31T21:00:00Z', '01/01/2027'],
            'dateStyle full' => [['dateStyle' => 'full'], '2026-10-12T09:00:00Z', 'Monday, 12 October 2026'],
            'dateStyle full thu' => [['dateStyle' => 'full'], '2026-10-08T05:30:00Z', 'Thursday, 8 October 2026'],
            'dateStyle full fri' => [['dateStyle' => 'full'], '2026-10-08T21:30:00Z', 'Friday, 9 October 2026'],
            // ICU: hour numeric alone is zero-padded, unpadded beside a minute
            'hour numeric alone' => [['hour' => 'numeric'], '2026-10-08T05:30:00Z', '08'],
            'hour numeric alone midnight' => [['hour' => 'numeric'], '2026-10-08T21:30:00Z', '00'],
            'hour numeric alone afternoon' => [['hour' => 'numeric'], '2026-01-15T12:00:00Z', '15'],
            'timeZoneName alone' => [['timeZoneName' => 'short'], '2026-10-12T09:00:00Z', '12/10/2026, GMT+3'],
            'timeZoneName alone january' => [['timeZoneName' => 'short'], '2026-01-15T12:00:00Z', '15/01/2026, GMT+3'],
            'with seconds' => [['hour' => '2-digit', 'minute' => '2-digit', 'second' => '2-digit'], '2026-10-12T09:00:00Z', '12:00:00'],
            'with seconds end of day' => [['hour' => '2-digit', 'minute' => '2-digit', 'second' => '2-digit'], '2026-10-08T20:59:00Z', '23:59:00'],
            'year month day' => [['year' => 'numeric', 'month' => 'long', 'day' => 'numeric'], '2026-10-12T09:00:00Z', '12 October 2026'],
            'year month day january' => [['year' => 'numeric', 'month' => 'long', 'day' => 'numeric'], '2026-01-15T12:00:00Z', '15 January 2026'],
            // no options → legacy default {dateStyle: medium, timeStyle: short}
            'default morning' => [null, '2026-10-08T05:30:00Z', '8 Oct 2026, 08:30'],
            'default noon' => [null, '2026-10-12T09:00:00Z', '12 Oct 2026, 12:00'],
            'default january' => [null, '2026-01-15T12:00:00Z', '15 Jan 2026, 15:00'],
            // empty options → bare Intl default pattern (short date)
            'empty options' => [[], '2026-10-12T09:00:00Z', '12/10/2026'],
            'empty options new year' => [[], '2026-12-31T21:00:00Z', '01/01/2027'],
        ];
    }

    #[DataProvider('icuFixtures')]
    public function test_format_in_tz_matches_node_icu_ground_truth(?array $options, string $instant, string $expected): void
    {
        $this->assertSame($expected, T::formatInTz($instant, self::ADDIS, $options));
    }

    public function test_unknown_style_values_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        T::formatInTz('2026-10-12T09:00:00Z', self::ADDIS, ['dateStyle' => 'bogus']);
    }

    public function test_invalid_input_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        T::formatInTz('not-a-date', self::ADDIS);
    }

    public function test_formats_date_ranges(): void
    {
        $this->assertSame('12 Oct 2026 → 15 Oct 2026', T::formatDateRange('2026-10-12T00:00:00Z', '2026-10-15T00:00:00Z', self::ADDIS));
        $this->assertSame('12 Oct 2026', T::formatDateRange('2026-10-12T00:00:00Z', '2026-10-12T23:59:00Z', self::ADDIS));
        $this->assertSame('12 Oct 2026', T::formatDateRange('2026-10-12T00:00:00Z', '2026-10-12T00:00:00Z', self::ADDIS));
        $this->assertSame('12 Oct 2026 → 14 Oct 2026', T::formatDateRange('2026-10-12', '2026-10-14', self::ADDIS));
        $this->assertSame('12 Oct 2026', T::formatDateRange('2026-10-12', '2026-10-12', self::ADDIS));
    }

    public function test_date_only_strings_are_utc_midnight_like_js_date(): void
    {
        // Legacy `new Date('2026-10-12')` is UTC midnight; in New York the
        // *UTC* calendar day is still the 12th but the medium date renders
        // the 11th — byte-compatible with the JS behaviour.
        $this->assertSame(
            '11 Oct 2026 → 12 Oct 2026',
            T::formatDateRange('2026-10-12', '2026-10-13', 'America/New_York'),
        );
    }

    public function test_naive_datetime_strings_parse_as_utc(): void
    {
        $this->assertSame(
            '2026-10-08T05:30:00.000Z',
            self::iso(T::combineDateAndTime(T::clinicDateKey('2026-10-08 08:30:00'), '08:30', self::ADDIS)),
        );
    }

    public function test_now_helpers(): void
    {
        $this->assertSame('Africa/Addis_Ababa', T::now()->getTimezone()->getName());
        $this->assertSame('UTC', T::nowUtc()->getTimezone()->getName());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', T::utcNowSql());
    }
}
