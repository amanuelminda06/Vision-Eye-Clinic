<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Service;

/**
 * Builds service/sale fixtures for feature tests.
 */
trait CreatesSales
{
    protected function createService(array $overrides = []): Service
    {
        // `name` and `name_en` are kept in sync on every real write (the
        // legacy invariant), so the fixture does the same.
        if (isset($overrides['name']) && ! isset($overrides['name_en'])) {
            $overrides['name_en'] = $overrides['name'];
        }

        return Service::factory()->create($overrides);
    }

    protected function createSale(array $overrides = [], array $lines = []): Sale
    {
        $sale = Sale::factory()->create($overrides);

        foreach ($lines as $line) {
            $service = $line['service'];
            $qty = (int) ($line['qty'] ?? 1);
            $unit = isset($line['unitPrice'])
                ? (float) $line['unitPrice']
                : (float) $service->current_price;
            $lineTotal = round($unit * $qty, 2);

            SaleLine::create([
                'sale_id' => $sale->id,
                'service_id' => $service->id,
                'service_name_snapshot' => $line['name'] ?? $service->name,
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
            ]);
        }

        if ($lines !== []) {
            $sale->forceFill([
                'total' => round(
                    SaleLine::query()->where('sale_id', $sale->id)->sum('line_total'),
                    2,
                ),
            ])->save();
        }

        return $sale->fresh();
    }
}
