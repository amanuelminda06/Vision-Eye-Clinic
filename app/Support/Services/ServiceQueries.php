<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Models\Service;
use App\Models\ServicePriceHistory;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service catalogue queries, ported from src/modules/services/queries.ts.
 *
 * Everything is portable SQL (LIKE with escaped wildcards) so the same code
 * runs on MySQL 8.0 and SQLite (tests). Prices serialize as 2 dp strings.
 */
final class ServiceQueries
{
    /**
     * @param  array{q?: ?string, category?: ?string, status?: ?string}  $filters
     */
    public static function buildServiceWhere(array $filters): Builder
    {
        $query = Service::query();

        $q = isset($filters['q']) ? trim($filters['q']) : '';
        if ($q !== '') {
            $escaped = self::escapeLike($q);
            $query->where(function ($inner) use ($escaped): void {
                $inner->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('name_am', 'like', '%'.$escaped.'%')
                    ->orWhere('name_en', 'like', '%'.$escaped.'%');
            });
        }

        if (! empty($filters['category']) && Categories::isServiceCategory($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (($filters['status'] ?? null) === 'true') {
            $query->where('active', true);
        }

        if (($filters['status'] ?? null) === 'false') {
            $query->where('active', false);
        }

        return $query;
    }

    /**
     * Service list (no pagination), active first then name.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Service>
     */
    public static function listServices(array $filters): Collection
    {
        return self::buildServiceWhere($filters)
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Active services only — used by the new-sale screen (prices shown there).
     *
     * @return Collection<int, Service>
     */
    public static function listActiveServices(): Collection
    {
        return Service::query()
            ->where('active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public static function getService(string $id): ?Service
    {
        return Service::query()->find($id);
    }

    /**
     * Price history for the modal (newest first, max 100) with changer names.
     * The transient `changed_by_name` attribute mirrors the legacy inline name.
     *
     * @return Collection<int, ServicePriceHistory>
     */
    public static function listPriceHistory(string $serviceId): Collection
    {
        $rows = ServicePriceHistory::query()
            ->where('service_id', $serviceId)
            ->orderByDesc('changed_at')
            ->take(100)
            ->get();

        $ids = array_values(array_unique($rows->pluck('changed_by')->all()));
        $names = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');

        foreach ($rows as $row) {
            $row->setAttribute('changed_by_name', $names->get($row->changed_by));
            $row->setAttribute('old_price', Money::toDecimalString($row->old_price));
            $row->setAttribute('new_price', Money::toDecimalString($row->new_price));
        }

        return $rows;
    }

    /**
     * Minimal option list for filters (id + name + nameEn), name asc.
     *
     * @return Collection<int, Service>
     */
    public static function listServiceOptions(): Collection
    {
        return Service::query()->orderBy('name')->get(['id', 'name', 'name_en']);
    }

    /** Escapes LIKE wildcards so a literal `%` never matches everything. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
