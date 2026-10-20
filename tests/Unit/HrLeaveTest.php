<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Hr\Leave;
use PHPUnit\Framework\TestCase;

/**
 * Leave geometry, ported from src/modules/hr/leave.test.ts.
 */
class HrLeaveTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function leave(array $partial = []): array
    {
        return array_merge([
            'id' => 'l1',
            'staffId' => 'p1',
            'type' => 'ANNUAL',
            'status' => 'APPROVED',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
            'halfDay' => false,
        ], $partial);
    }

    public function test_it_counts_inclusive_calendar_days(): void
    {
        $this->assertSame(1, Leave::countCalendarDays('2026-10-08', '2026-10-08'));
        $this->assertSame(3, Leave::countCalendarDays('2026-10-08', '2026-10-10'));
    }

    public function test_a_reversed_or_malformed_range_counts_as_one_day(): void
    {
        $this->assertSame(1, Leave::countCalendarDays('2026-10-10', '2026-10-08'));
        $this->assertSame(1, Leave::countCalendarDays('nope', '2026-10-08'));
    }

    public function test_half_days_count_half(): void
    {
        $this->assertSame(0.5, Leave::requestedDays([
            'startDate' => '2026-10-08', 'endDate' => '2026-10-08', 'halfDay' => true,
        ]));
        $this->assertSame(1.0, Leave::requestedDays([
            'startDate' => '2026-10-08', 'endDate' => '2026-10-08', 'halfDay' => false,
        ]));
    }

    public function test_it_detects_overlapping_inclusive_ranges(): void
    {
        $this->assertTrue(Leave::leaveRangesOverlap('2026-10-08', '2026-10-10', '2026-10-10', '2026-10-12'));
        $this->assertFalse(Leave::leaveRangesOverlap('2026-10-08', '2026-10-10', '2026-10-11', '2026-10-12'));
    }

    public function test_it_rejects_a_request_overlapping_an_existing_leave_for_the_same_person(): void
    {
        $existing = [$this->leave(['id' => 'l1', 'startDate' => '2026-10-08', 'endDate' => '2026-10-12'])];

        $clash = Leave::findOverlappingLeave($existing, [
            'staffId' => 'p1',
            'startDate' => '2026-10-11',
            'endDate' => '2026-10-15',
        ]);
        $this->assertSame('l1', $clash['id']);

        $other = Leave::findOverlappingLeave($existing, [
            'staffId' => 'p2',
            'startDate' => '2026-10-11',
            'endDate' => '2026-10-15',
        ]);
        $this->assertNull($other);
    }

    public function test_only_approved_leave_covers_a_date(): void
    {
        $approved = $this->leave([
            'id' => 'l1', 'status' => 'APPROVED', 'startDate' => '2026-10-08', 'endDate' => '2026-10-10',
        ]);
        $pending = $this->leave([
            'id' => 'l2', 'status' => 'REQUESTED', 'startDate' => '2026-10-08', 'endDate' => '2026-10-08',
        ]);

        $this->assertTrue(Leave::leaveCoversDate($approved, '2026-10-09'));
        $this->assertFalse(Leave::leaveCoversDate($approved, '2026-10-11'));
        $this->assertFalse(Leave::leaveCoversDate($pending, '2026-10-08'));

        $this->assertTrue(Leave::isOnLeaveAt([$approved], 'p1', '2026-10-09'));
        $this->assertFalse(Leave::isOnLeaveAt([$approved], 'p2', '2026-10-09'));
    }

    public function test_it_computes_the_annual_balance_for_the_year(): void
    {
        $leaves = [
            $this->leave(['id' => 'a', 'startDate' => '2026-03-02', 'endDate' => '2026-03-04']),
            $this->leave(['id' => 'b', 'startDate' => '2026-06-01', 'endDate' => '2026-06-01', 'halfDay' => true]),
            $this->leave(['id' => 'c', 'startDate' => '2026-07-01', 'endDate' => '2026-07-02', 'type' => 'SICK']),
            $this->leave(['id' => 'd', 'startDate' => '2025-12-30', 'endDate' => '2025-12-31']),
            $this->leave(['id' => 'e', 'startDate' => '2026-08-01', 'endDate' => '2026-08-02', 'status' => 'REQUESTED']),
        ];

        $balance = Leave::leaveBalance(20, $leaves, 2026);

        $this->assertSame(3.5, $balance['taken']);
        $this->assertSame(16.5, $balance['remaining']);
        $this->assertSame(20, $balance['entitlement']);
    }

    public function test_it_returns_no_balance_when_the_entitlement_is_unset(): void
    {
        $balance = Leave::leaveBalance(null, [], 2026);

        $this->assertNull($balance['entitlement']);
        $this->assertNull($balance['remaining']);
        $this->assertSame(0.0, $balance['taken']);
    }

    public function test_the_leave_range_returns_the_inclusive_keys(): void
    {
        $range = Leave::leaveRange(['startDate' => '2026-10-08', 'endDate' => '2026-10-12']);

        $this->assertSame('2026-10-08', $range['fromKey']);
        $this->assertSame('2026-10-12', $range['toKey']);
    }
}
