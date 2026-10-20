<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Support\ClinicTime;
use App\Support\Money;

/**
 * Revenue aggregation (pure — unit tested), ported 1:1 from
 * src/modules/sales/revenue.ts.
 *
 * Only CONFIRMED sales count as revenue; VOIDED totals are reported separately
 * and DRAFT is ignored. Day buckets use the clinic timezone, never the
 * server's local time.
 *
 * Money accumulates through `Money::addMoney` (exact decimal strings) rather
 * than Decimal objects — the observable results are identical because every
 * bucket total is re-normalised to 2 dp half-up before serialising.
 */
final class Revenue
{
    /** @var list<string> */
    public const PERIOD_PRESETS = ['today', 'week', 'month', 'lastMonth', 'custom'];

    public static function isPeriodPreset(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::PERIOD_PRESETS, true);
    }

    /**
     * @param  list<array{id: string, saleDate: \DateTimeInterface, status: string, total: string, paymentMethod: string, doctorName: string|null, lines: list<array{serviceName: string, category: string, qty: int, lineTotal: string}>}>  $sales
     * @return array{totalRevenue: string, salesCount: int, averageSale: string, voidedTotal: string, voidedCount: int, byService: list<array{key: string, label: string, total: string, count: int}>, byCategory: list<array{key: string, label: string, total: string, count: int}>, byDoctor: list<array{key: string, label: string, total: string, count: int}>, byPaymentMethod: list<array{key: string, label: string, total: string, count: int}>, byDay: list<array{dateKey: string, total: string, count: int}>}
     */
    public static function buildRevenueReport(array $sales, string $timeZone): array
    {
        $confirmed = array_values(array_filter($sales, fn (array $s): bool => $s['status'] === 'CONFIRMED'));
        $voided = array_values(array_filter($sales, fn (array $s): bool => $s['status'] === 'VOIDED'));

        $byService = [];
        $byCategory = [];
        $byDoctor = [];
        $byPaymentMethod = [];
        $byDay = [];

        foreach ($confirmed as $sale) {
            $dayKey = ClinicTime::clinicDateKey($sale['saleDate'], $timeZone);
            self::bump($byDay, $dayKey, $dayKey, $sale['total']);

            $doctorKey = $sale['doctorName'] ?? '';
            self::bump($byDoctor, $doctorKey, $sale['doctorName'] ?? '—', $sale['total']);
            self::bump($byPaymentMethod, $sale['paymentMethod'], $sale['paymentMethod'], $sale['total']);

            foreach ($sale['lines'] as $line) {
                self::bump($byService, $line['serviceName'], $line['serviceName'], $line['lineTotal']);
                self::bump($byCategory, $line['category'], $line['category'], $line['lineTotal']);
            }
        }

        $totalRevenue = Money::addMoney(...array_map(fn (array $s): string => $s['total'], $confirmed));
        $voidedTotal = Money::addMoney(...array_map(fn (array $s): string => $s['total'], $voided));

        return [
            'totalRevenue' => Money::toDecimalString($totalRevenue),
            'salesCount' => count($confirmed),
            'averageSale' => Money::toDecimalString(Money::averageMoney($totalRevenue, count($confirmed))),
            'voidedTotal' => Money::toDecimalString($voidedTotal),
            'voidedCount' => count($voided),
            'byService' => self::toBuckets($byService),
            'byCategory' => self::toBuckets($byCategory),
            'byDoctor' => self::toBuckets($byDoctor),
            'byPaymentMethod' => self::toBuckets($byPaymentMethod),
            'byDay' => self::dayBuckets($byDay),
        ];
    }

    /**
     * Continuous day list (zero-filled) for the trend chart.
     *
     * @param  list<array{dateKey: string, total: string, count: int}>  $days
     * @return list<array{dateKey: string, total: string, count: int}>
     */
    public static function fillDailyTrend(array $days, string $fromKey, string $toKey): array
    {
        $byKey = [];
        foreach ($days as $day) {
            $byKey[$day['dateKey']] = $day;
        }

        $filled = [];
        $cursor = $fromKey;

        for ($i = 0; $i <= 400; $i++) {
            $filled[] = $byKey[$cursor] ?? ['dateKey' => $cursor, 'total' => '0.00', 'count' => 0];

            if ($cursor === $toKey) {
                break;
            }

            $cursor = ClinicTime::dateKeyAdd($cursor, 1);
        }

        return $filled;
    }

    /** Re-exported from Money (percent change, 1 dp, null when previous = 0). */
    public static function percentChange(int|float|string $current, int|float|string $previous): ?float
    {
        return Money::percentChange($current, $previous);
    }

    /**
     * Resolves a preset into the current period and the previous equivalent one
     * (used for the % comparison). All boundaries are clinic timezone calendar
     * days.
     *
     * @param  array{from?: string, to?: string}  $custom
     * @return array{current: array{fromKey: string, toKey: string}, previous: array{fromKey: string, toKey: string}}
     */
    public static function resolvePeriod(string $preset, \DateTimeInterface $now, string $timeZone, array $custom = []): array
    {
        $today = ClinicTime::clinicDateKey($now, $timeZone);
        [$year, $month] = array_map('intval', explode('-', $today));

        switch ($preset) {
            case 'week':
                // JS getUTCDay: 0 = Sunday. sinceMonday = (weekday + 6) % 7.
                $weekday = (int) (new \DateTimeImmutable($today.'T00:00:00Z'))->format('w');
                $sinceMonday = ($weekday + 6) % 7;
                $current = ['fromKey' => ClinicTime::dateKeyAdd($today, -$sinceMonday), 'toKey' => $today];
                break;

            case 'month':
                $current = ['fromKey' => sprintf('%04d-%02d-01', $year, $month), 'toKey' => $today];
                break;

            case 'lastMonth':
                $prevMonth = $month === 1 ? 12 : $month - 1;
                $prevYear = $month === 1 ? $year - 1 : $year;
                $current = self::monthRange($prevYear, $prevMonth);
                $beforeMonth = $prevMonth === 1 ? 12 : $prevMonth - 1;
                $beforeYear = $prevMonth === 1 ? $prevYear - 1 : $prevYear;

                return ['current' => $current, 'previous' => self::monthRange($beforeYear, $beforeMonth)];

            case 'custom':
                $from = isset($custom['from']) && self::isDateKey($custom['from']) ? $custom['from'] : $today;
                $to = isset($custom['to']) && self::isDateKey($custom['to']) ? $custom['to'] : $today;
                $current = $from <= $to
                    ? ['fromKey' => $from, 'toKey' => $to]
                    : ['fromKey' => $to, 'toKey' => $from];
                break;

            case 'today':
            default:
                $current = ['fromKey' => $today, 'toKey' => $today];
        }

        return ['current' => $current, 'previous' => self::previousEquivalent($current)];
    }

    /**
     * True when a clinic-tz date key falls inside a range (inclusive).
     *
     * @param  array{fromKey: string, toKey: string}  $range
     */
    public static function inRange(string $dateKey, array $range): bool
    {
        return $dateKey >= $range['fromKey'] && $dateKey <= $range['toKey'];
    }

    /** Whole-day span length, inclusive (UTC date-only math). */
    private static function dayCount(string $fromKey, string $toKey): int
    {
        $from = (int) (new \DateTimeImmutable($fromKey.'T00:00:00Z'))->getTimestamp();
        $to = (int) (new \DateTimeImmutable($toKey.'T00:00:00Z'))->getTimestamp();

        return (int) round(($to - $from) / 86400) + 1;
    }

    /**
     * @param  array{fromKey: string, toKey: string}  $range
     * @return array{fromKey: string, toKey: string}
     */
    private static function previousEquivalent(array $range): array
    {
        $length = self::dayCount($range['fromKey'], $range['toKey']);
        $toKey = ClinicTime::dateKeyAdd($range['fromKey'], -1);

        return ['fromKey' => ClinicTime::dateKeyAdd($toKey, -($length - 1)), 'toKey' => $toKey];
    }

    /** @return array{fromKey: string, toKey: string} */
    private static function monthRange(int $year, int $month): array
    {
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00Z', $year, $month)))
            ->modify('last day of this month')
            ->format('j');

        return [
            'fromKey' => sprintf('%04d-%02d-01', $year, $month),
            'toKey' => sprintf('%04d-%02d-%02d', $year, $month, $lastDay),
        ];
    }

    private static function isDateKey(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * @param  array<string, array{total: string, count: int, label: string}>  $map
     */
    private static function bump(array &$map, string $key, string $label, string $amount): void
    {
        if (isset($map[$key])) {
            $map[$key]['total'] = Money::addMoney($map[$key]['total'], $amount);
            $map[$key]['count']++;
        } else {
            $map[$key] = ['total' => Money::addMoney($amount), 'count' => 1, 'label' => $label];
        }
    }

    /**
     * @param  array<string, array{total: string, count: int, label: string}>  $map
     * @return list<array{key: string, label: string, total: string, count: int}>
     */
    private static function toBuckets(array $map): array
    {
        $buckets = [];
        foreach ($map as $key => $value) {
            $buckets[] = [
                'key' => $key,
                'label' => $value['label'],
                'total' => $value['total'],
                'count' => $value['count'],
            ];
        }

        usort($buckets, function (array $a, array $b): int {
            $cmp = bccomp($b['total'], $a['total'], 2);

            return $cmp !== 0 ? $cmp : strcmp($a['key'], $b['key']);
        });

        return $buckets;
    }

    /**
     * @param  array<string, array{total: string, count: int, label: string}>  $map
     * @return list<array{dateKey: string, total: string, count: int}>
     */
    private static function dayBuckets(array $map): array
    {
        $days = [];
        foreach ($map as $dateKey => $value) {
            $days[] = ['dateKey' => $dateKey, 'total' => $value['total'], 'count' => $value['count']];
        }

        usort($days, fn (array $a, array $b): int => strcmp($a['dateKey'], $b['dateKey']));

        return $days;
    }
}
