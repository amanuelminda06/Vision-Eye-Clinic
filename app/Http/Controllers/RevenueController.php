<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ClinicTime;
use App\Support\Sales\Revenue;
use App\Support\Sales\SaleQueries;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revenue dashboard (OWNER only), ported from the /revenue page +
 * listRevenueSales.
 *
 * One fetch spans the union of the current and previous periods; the report is
 * split in memory by clinic-tz day. Only CONFIRMED sales count as revenue;
 * VOIDED is reported separately and DRAFT is never fetched.
 */
class RevenueController extends Controller
{
    /** GET /revenue — presets + totals + breakdowns. */
    public function index(Request $request): Response
    {
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $presetRaw = (string) $request->query('period', 'today');
        $preset = Revenue::isPeriodPreset($presetRaw) ? $presetRaw : 'today';

        $custom = [
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $period = Revenue::resolvePeriod($preset, $now, $tz, $custom);
        $current = $period['current'];
        $previous = $period['previous'];

        // Widest span needed covers both periods; each is expanded to UTC
        // instants at the clinic-tz day boundaries.
        $earliestFrom = min($current['fromKey'], $previous['fromKey']);
        $latestTo = max($current['toKey'], $previous['toKey']);

        $sales = SaleQueries::listRevenueSales(
            ClinicTime::startOfDay($earliestFrom, $tz),
            ClinicTime::endOfDay($latestTo, $tz),
        );

        $currentSales = array_values(array_filter(
            $sales,
            fn (array $sale): bool => Revenue::inRange(ClinicTime::clinicDateKey($sale['saleDate'], $tz), $current),
        ));
        $previousSales = array_values(array_filter(
            $sales,
            fn (array $sale): bool => Revenue::inRange(ClinicTime::clinicDateKey($sale['saleDate'], $tz), $previous),
        ));

        $currentReport = Revenue::buildRevenueReport($currentSales, $tz);
        $previousReport = Revenue::buildRevenueReport($previousSales, $tz);

        $trend = Revenue::fillDailyTrend($currentReport['byDay'], $current['fromKey'], $current['toKey']);
        $trendMax = max(1, ...array_map(fn (array $day): float => (float) $day['total'], $trend));

        return response()->view('sales.revenue', [
            'preset' => $preset,
            'custom' => $custom,
            'current' => $current,
            'report' => $currentReport,
            'change' => Revenue::percentChange($currentReport['totalRevenue'], $previousReport['totalRevenue']),
            'trend' => $trend,
            'trendMax' => $trendMax,
            'currency' => Settings::currency(),
            'timezone' => $tz,
        ]);
    }
}
