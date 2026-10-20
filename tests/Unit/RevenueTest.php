<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sales\Revenue;
use PHPUnit\Framework\TestCase;

/**
 * Revenue aggregation and period presets, ported from
 * src/modules/sales/revenue.test.ts.
 */
class RevenueTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    /**
     * Fixture helper mirroring the legacy `sale({id,total,status})` defaults:
     * saleDate 2026-10-08T09:00Z, CASH, no doctor, one consultation line.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sale(array $overrides = []): array
    {
        $total = (string) ($overrides['total'] ?? '0.00');

        return array_merge([
            'id' => $overrides['id'] ?? 'sale-'.uniqid(),
            'saleDate' => $overrides['saleDate'] ?? new \DateTimeImmutable('2026-10-08T09:00:00Z'),
            'status' => $overrides['status'] ?? 'CONFIRMED',
            'total' => $total,
            'paymentMethod' => $overrides['paymentMethod'] ?? 'CASH',
            'doctorName' => $overrides['doctorName'] ?? null,
            'lines' => $overrides['lines'] ?? [[
                'serviceName' => 'Eye Consultation',
                'category' => 'consultation',
                'qty' => 1,
                'lineTotal' => $total,
            ]],
        ], []);
    }

    public function test_it_counts_only_confirmed_as_revenue_and_reports_voided_separately(): void
    {
        $report = Revenue::buildRevenueReport([
            $this->sale(['total' => '300.00']),
            $this->sale(['total' => '500.00']),
            $this->sale(['total' => '900.00', 'status' => 'VOIDED']),
            $this->sale(['total' => '1500.00', 'status' => 'DRAFT']),
        ], self::TZ);

        $this->assertSame('800.00', $report['totalRevenue']);
        $this->assertSame(2, $report['salesCount']);
        $this->assertSame('400.00', $report['averageSale']);
        $this->assertSame('900.00', $report['voidedTotal']);
        $this->assertSame(1, $report['voidedCount']);
    }

    public function test_it_breaks_revenue_down_by_service_category_doctor_and_payment(): void
    {
        $report = Revenue::buildRevenueReport([
            $this->sale([
                'total' => '1000.00',
                'paymentMethod' => 'CARD',
                'doctorName' => 'Dr. Abebe',
                'lines' => [['serviceName' => 'OCT Scan', 'category' => 'diagnostics', 'qty' => 1, 'lineTotal' => '1000.00']],
            ]),
            $this->sale([
                'total' => '300.00',
                'paymentMethod' => 'CARD',
                'doctorName' => 'Dr. Abebe',
                'lines' => [['serviceName' => 'Eye Consultation', 'category' => 'consultation', 'qty' => 1, 'lineTotal' => '300.00']],
            ]),
        ], self::TZ);

        $this->assertSame(['OCT Scan', 'Eye Consultation'], array_column($report['byService'], 'key'));
        $this->assertSame('1000.00', $report['byService'][0]['total']);

        $category = collect($report['byCategory'])->firstWhere('key', 'consultation');
        $this->assertSame('300.00', $category['total']);

        $this->assertSame('Dr. Abebe', $report['byDoctor'][0]['key']);
        $this->assertSame('1300.00', $report['byDoctor'][0]['total']);
        $this->assertSame(2, $report['byDoctor'][0]['count']);

        $this->assertSame('CARD', $report['byPaymentMethod'][0]['key']);
        $this->assertSame('1300.00', $report['byPaymentMethod'][0]['total']);
    }

    public function test_it_buckets_days_in_the_clinic_timezone(): void
    {
        $report = Revenue::buildRevenueReport([
            $this->sale(['total' => '100.00', 'saleDate' => new \DateTimeImmutable('2026-10-08T20:30:00Z')]),
            $this->sale(['total' => '200.00', 'saleDate' => new \DateTimeImmutable('2026-10-08T21:30:00Z')]),
        ], self::TZ);

        $byDay = collect($report['byDay'])->keyBy('dateKey');
        $this->assertSame('100.00', $byDay['2026-10-08']['total']);
        $this->assertSame('200.00', $byDay['2026-10-09']['total']);
    }

    public function test_it_zero_fills_missing_days(): void
    {
        $filled = Revenue::fillDailyTrend(
            [['dateKey' => '2026-10-02', 'total' => '50.00', 'count' => 1]],
            '2026-10-01',
            '2026-10-03',
        );

        $this->assertSame([
            ['dateKey' => '2026-10-01', 'total' => '0.00', 'count' => 0],
            ['dateKey' => '2026-10-02', 'total' => '50.00', 'count' => 1],
            ['dateKey' => '2026-10-03', 'total' => '0.00', 'count' => 0],
        ], $filled);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-08T12:00:00Z'); // Thursday
    }

    public function test_it_resolves_today_with_yesterday_as_the_previous_period(): void
    {
        $period = Revenue::resolvePeriod('today', $this->now(), self::TZ);

        $this->assertSame(['fromKey' => '2026-10-08', 'toKey' => '2026-10-08'], $period['current']);
        $this->assertSame(['fromKey' => '2026-10-07', 'toKey' => '2026-10-07'], $period['previous']);
    }

    public function test_it_resolves_the_current_week_from_monday(): void
    {
        $period = Revenue::resolvePeriod('week', $this->now(), self::TZ);

        $this->assertSame(['fromKey' => '2026-10-05', 'toKey' => '2026-10-08'], $period['current']);
        $this->assertSame(['fromKey' => '2026-10-01', 'toKey' => '2026-10-04'], $period['previous']);
    }

    public function test_it_resolves_the_month_and_the_previous_equivalent_span(): void
    {
        $period = Revenue::resolvePeriod('month', $this->now(), self::TZ);

        $this->assertSame(['fromKey' => '2026-10-01', 'toKey' => '2026-10-08'], $period['current']);
        $this->assertSame(['fromKey' => '2026-09-23', 'toKey' => '2026-09-30'], $period['previous']);
    }

    public function test_it_resolves_last_month_handling_the_january_boundary(): void
    {
        $september = Revenue::resolvePeriod('lastMonth', $this->now(), self::TZ);
        $this->assertSame(['fromKey' => '2026-09-01', 'toKey' => '2026-09-30'], $september['current']);
        $this->assertSame(['fromKey' => '2026-08-01', 'toKey' => '2026-08-31'], $september['previous']);

        $january = Revenue::resolvePeriod('lastMonth', new \DateTimeImmutable('2026-01-15T12:00:00Z'), self::TZ);
        $this->assertSame(['fromKey' => '2025-12-01', 'toKey' => '2025-12-31'], $january['current']);
        $this->assertSame(['fromKey' => '2025-11-01', 'toKey' => '2025-11-30'], $january['previous']);
    }

    public function test_it_falls_back_to_today_for_invalid_custom_ranges(): void
    {
        $period = Revenue::resolvePeriod('custom', $this->now(), self::TZ, ['from' => 'nope', 'to' => '']);

        $this->assertSame(['fromKey' => '2026-10-08', 'toKey' => '2026-10-08'], $period['current']);
    }

    public function test_it_swaps_reversed_custom_ranges(): void
    {
        $period = Revenue::resolvePeriod('custom', $this->now(), self::TZ, ['from' => '2026-10-05', 'to' => '2026-10-01']);

        $this->assertSame(['fromKey' => '2026-10-01', 'toKey' => '2026-10-05'], $period['current']);
        $this->assertSame(['fromKey' => '2026-09-26', 'toKey' => '2026-09-30'], $period['previous']);
    }

    public function test_it_checks_inclusive_ranges(): void
    {
        $range = ['fromKey' => '2026-10-01', 'toKey' => '2026-10-05'];

        $this->assertTrue(Revenue::inRange('2026-10-03', $range));
        $this->assertFalse(Revenue::inRange('2026-09-30', $range));
    }

    public function test_it_re_exports_percent_change(): void
    {
        $this->assertSame(10.0, Revenue::percentChange('110.00', '100.00'));
    }

    public function test_average_sale_never_divides_by_zero(): void
    {
        $report = Revenue::buildRevenueReport([], self::TZ);

        $this->assertSame('0.00', $report['totalRevenue']);
        $this->assertSame(0, $report['salesCount']);
        $this->assertSame('0.00', $report['averageSale']);
        $this->assertSame('0.00', $report['voidedTotal']);
    }
}
