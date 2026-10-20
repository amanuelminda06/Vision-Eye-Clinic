<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Support\Money;

/**
 * Sales line computation (pure — unit tested), ported 1:1 from
 * src/modules/sales/lines.ts.
 *
 * The client only sends `serviceId` + `qty`. Prices, names and totals are
 * ALWAYS taken from the database rows here, so a tampered client price is
 * impossible (it is never read), and the line keeps the price that was current
 * when the sale was made.
 */
final class Lines
{
    /**
     * @param  list<array{id: string, name: string, currentPrice: string, active: bool}>  $services
     * @param  list<array{serviceId: string, qty: int}>  $requested
     * @return array{ok: true, lines: list<array{serviceId: string, serviceNameSnapshot: string, qty: int, unitPrice: string, lineTotal: string}>, total: string}
     *                                                                                                                                                            |array{ok: false, error: string, serviceNames: list<string>}
     */
    public static function computeSaleLines(array $services, array $requested): array
    {
        $byId = [];
        foreach ($services as $service) {
            $byId[$service['id']] = $service;
        }

        $merged = self::mergeRequested($requested);

        $missing = [];
        $inactive = [];
        $lines = [];

        foreach ($merged as $requestedLine) {
            $service = $byId[$requestedLine['serviceId']] ?? null;

            if ($service === null) {
                $missing[] = $requestedLine['serviceId'];

                continue;
            }

            if (! $service['active']) {
                $inactive[] = $service['name'];

                continue;
            }

            if ($requestedLine['qty'] < 1 || $requestedLine['qty'] > Constants::MAX_QTY) {
                return ['ok' => false, 'error' => 'quantityTooHigh', 'serviceNames' => [$service['name']]];
            }

            $lines[] = [
                'serviceId' => $service['id'],
                'serviceNameSnapshot' => $service['name'],
                'qty' => $requestedLine['qty'],
                'unitPrice' => Money::toDecimalString(Money::multiplyMoney($service['currentPrice'], 1)),
                'lineTotal' => Money::toDecimalString(Money::multiplyMoney($service['currentPrice'], $requestedLine['qty'])),
            ];
        }

        if ($inactive !== []) {
            return ['ok' => false, 'error' => 'serviceInactive', 'serviceNames' => $inactive];
        }

        if ($missing !== []) {
            return ['ok' => false, 'error' => 'serviceMissing', 'serviceNames' => $missing];
        }

        if ($lines === []) {
            return ['ok' => false, 'error' => 'serviceMissing', 'serviceNames' => []];
        }

        $total = Money::sumLineTotals(array_map(
            fn (array $line): array => ['unitPrice' => $line['unitPrice'], 'quantity' => $line['qty']],
            $lines,
        ));

        return ['ok' => true, 'lines' => $lines, 'total' => Money::toDecimalString($total)];
    }

    /**
     * Merges duplicate service rows (same service added twice → summed qty),
     * preserving first-seen order (legacy Map insertion order).
     *
     * @param  list<array{serviceId: string, qty: int}>  $requested
     * @return list<array{serviceId: string, qty: int}>
     */
    private static function mergeRequested(array $requested): array
    {
        $merged = [];
        $order = [];

        foreach ($requested as $line) {
            $serviceId = $line['serviceId'];
            if (! array_key_exists($serviceId, $merged)) {
                $merged[$serviceId] = 0;
                $order[] = $serviceId;
            }
            $merged[$serviceId] += $line['qty'];
        }

        $result = [];
        foreach ($order as $serviceId) {
            $result[] = ['serviceId' => $serviceId, 'qty' => $merged[$serviceId]];
        }

        return $result;
    }
}
