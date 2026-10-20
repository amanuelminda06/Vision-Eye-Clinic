<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ClinicTime;
use App\Support\Hr\Shifts;
use PHPUnit\Framework\TestCase;

/**
 * Shift geometry, ported from src/modules/hr/shifts.test.ts.
 */
class HrShiftsTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    private function at(string $dateKey, string $time): \DateTimeImmutable
    {
        return ClinicTime::combineDateAndTime($dateKey, $time, self::TZ);
    }

    public function test_it_detects_overnight_templates(): void
    {
        $this->assertTrue(Shifts::isOvernight('22:00', '06:00'));
        $this->assertFalse(Shifts::isOvernight('06:00', '22:00'));
        $this->assertTrue(Shifts::isOvernight('09:00', '09:00'));
    }

    public function test_it_places_an_overnight_shift_on_the_next_day(): void
    {
        $instants = Shifts::shiftInstantsForDay(
            ['startTime' => '22:00', 'endTime' => '06:00'],
            '2026-10-08',
            self::TZ,
        );

        $this->assertSame(
            $this->at('2026-10-08', '22:00')->getTimestamp(),
            $instants['startsAt']->getTimestamp(),
        );
        $this->assertSame(
            $this->at('2026-10-09', '06:00')->getTimestamp(),
            $instants['endsAt']->getTimestamp(),
        );
        $this->assertSame(8 * 3600, $instants['endsAt']->getTimestamp() - $instants['startsAt']->getTimestamp());
    }

    public function test_it_keeps_a_same_day_shift_on_the_same_day(): void
    {
        $instants = Shifts::shiftInstantsForDay(
            ['startTime' => '09:00', 'endTime' => '17:00'],
            '2026-10-08',
            self::TZ,
        );

        $this->assertSame(8 * 3600, $instants['endsAt']->getTimestamp() - $instants['startsAt']->getTimestamp());
    }

    public function test_overlap_is_symmetric_and_half_open(): void
    {
        $a = ['s' => $this->at('2026-10-08', '09:00'), 'e' => $this->at('2026-10-08', '12:00')];
        $b = ['s' => $this->at('2026-10-08', '12:00'), 'e' => $this->at('2026-10-08', '15:00')];
        $c = ['s' => $this->at('2026-10-08', '11:00'), 'e' => $this->at('2026-10-08', '13:00')];

        $this->assertFalse(Shifts::shiftsOverlap($a['s'], $a['e'], $b['s'], $b['e']));
        $this->assertTrue(Shifts::shiftsOverlap($a['s'], $a['e'], $c['s'], $c['e']));
        $this->assertTrue(Shifts::shiftsOverlap($c['s'], $c['e'], $a['s'], $a['e']));
    }

    public function test_it_finds_an_overlapping_shift_for_the_same_person_only(): void
    {
        $existing = [
            ['id' => 's1', 'staffId' => 'p1', 'startsAt' => $this->at('2026-10-08', '09:00'), 'endsAt' => $this->at('2026-10-08', '17:00')],
            ['id' => 's2', 'staffId' => 'p2', 'startsAt' => $this->at('2026-10-08', '09:00'), 'endsAt' => $this->at('2026-10-08', '17:00')],
        ];

        $clash = Shifts::findOverlappingShift($existing, [
            'staffId' => 'p1',
            'startsAt' => $this->at('2026-10-08', '16:00'),
            'endsAt' => $this->at('2026-10-08', '20:00'),
        ]);
        $this->assertSame('s1', $clash['id']);

        $other = Shifts::findOverlappingShift($existing, [
            'staffId' => 'p3',
            'startsAt' => $this->at('2026-10-08', '16:00'),
            'endsAt' => $this->at('2026-10-08', '20:00'),
        ]);
        $this->assertNull($other);
    }

    public function test_it_ignores_the_shift_being_edited(): void
    {
        $existing = [
            ['id' => 's1', 'staffId' => 'p1', 'startsAt' => $this->at('2026-10-08', '09:00'), 'endsAt' => $this->at('2026-10-08', '17:00')],
        ];

        $clash = Shifts::findOverlappingShift($existing, [
            'staffId' => 'p1',
            'startsAt' => $this->at('2026-10-08', '10:00'),
            'endsAt' => $this->at('2026-10-08', '18:00'),
            'ignoreId' => 's1',
        ]);

        $this->assertNull($clash);
    }

    public function test_it_detects_active_and_next_shifts(): void
    {
        $shift = [
            'id' => 's1',
            'staffId' => 'p1',
            'startsAt' => $this->at('2026-10-08', '09:00'),
            'endsAt' => $this->at('2026-10-08', '17:00'),
        ];
        $later = [
            'id' => 's2',
            'staffId' => 'p1',
            'startsAt' => $this->at('2026-10-09', '09:00'),
            'endsAt' => $this->at('2026-10-09', '17:00'),
        ];

        $this->assertTrue(Shifts::isShiftActiveAt($shift, $this->at('2026-10-08', '10:00')));
        $this->assertFalse(Shifts::isShiftActiveAt($shift, $this->at('2026-10-08', '17:00')));

        $next = Shifts::nextShiftAfter([$shift, $later], $this->at('2026-10-08', '10:00'));
        $this->assertSame('s2', $next['id']);

        $this->assertNull(Shifts::nextShiftAfter([$shift], $this->at('2026-10-08', '18:00')));
    }

    /** @return list<array<string, mixed>> */
    private function templates(): array
    {
        $thursday = Shifts::weekdayOf('2026-10-08');

        return [
            [
                'id' => 't1',
                'staffId' => 'p1',
                'weekday' => $thursday,
                'startTime' => '09:00',
                'endTime' => '17:00',
                'validFrom' => '2026-10-01',
                'validTo' => null,
            ],
            [
                'id' => 't2',
                'staffId' => 'p1',
                'weekday' => $thursday,
                'startTime' => '20:00',
                'endTime' => '04:00',
                'validFrom' => '2026-10-01',
                'validTo' => null,
            ],
        ];
    }

    public function test_it_generates_matching_weekdays_including_overnight(): void
    {
        $shifts = Shifts::generateTemplateShifts($this->templates(), '2026-10-08', '2026-10-21', self::TZ);

        $this->assertCount(4, $shifts);

        $overnight = collect($shifts)->firstWhere('templateId', 't2');
        $this->assertSame(
            $this->at('2026-10-08', '20:00')->getTimestamp(),
            $overnight['startsAt']->getTimestamp(),
        );
        $this->assertSame(
            $this->at('2026-10-09', '04:00')->getTimestamp(),
            $overnight['endsAt']->getTimestamp(),
        );
    }

    public function test_it_respects_the_validity_window(): void
    {
        $templates = $this->templates();
        $late = [array_merge($templates[0], ['validFrom' => '2026-10-10', 'validTo' => '2026-10-20'])];

        $shifts = Shifts::generateTemplateShifts($late, '2026-10-08', '2026-10-21', self::TZ);

        $this->assertCount(1, $shifts);
        $this->assertSame('2026-10-15', $shifts[0]['dateKey']);
    }

    public function test_it_builds_inclusive_day_lists_and_monday_based_weeks(): void
    {
        $this->assertSame(
            ['2026-10-08', '2026-10-09', '2026-10-10'],
            Shifts::dateKeysBetween('2026-10-08', '2026-10-10'),
        );
        $this->assertSame('2026-10-05', Shifts::weekStartKey('2026-10-08'));
        $this->assertCount(7, Shifts::weekDays('2026-10-05'));
        $this->assertSame('2026-10-11', Shifts::weekDays('2026-10-05')[6]);
    }

    public function test_shift_by_days_advances_an_overnight_shift_by_seven_days(): void
    {
        $shifted = Shifts::shiftByDays([
            'staffId' => 'p1',
            'startsAt' => $this->at('2026-10-08', '22:00'),
            'endsAt' => $this->at('2026-10-09', '06:00'),
        ], 7);

        $this->assertSame(
            $this->at('2026-10-15', '22:00')->getTimestamp(),
            $shifted['startsAt']->getTimestamp(),
        );
        $this->assertSame(
            $this->at('2026-10-16', '06:00')->getTimestamp(),
            $shifted['endsAt']->getTimestamp(),
        );
    }

    public function test_the_date_key_list_is_capped(): void
    {
        // The loop pushes then breaks, so it yields at most maxDays + 1 keys.
        $keys = Shifts::dateKeysBetween('2026-10-08', '2026-12-31', 5);

        $this->assertCount(6, $keys);
        $this->assertSame('2026-10-08', $keys[0]);
        $this->assertSame('2026-10-13', $keys[5]);
    }

    public function test_the_date_column_normaliser_handles_dates_and_strings(): void
    {
        $this->assertSame('2026-10-08', Shifts::dateColumnToKey('2026-10-08'));
        $this->assertSame('2026-10-08', Shifts::dateColumnToKey(
            new \DateTimeImmutable('2026-10-08T00:00:00Z'),
        ));
    }
}
