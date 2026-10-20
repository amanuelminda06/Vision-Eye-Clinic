<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Staff;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sales list/detail queries, ported from src/modules/sales/queries.ts.
 *
 * Everything here is portable SQL (LIKE with escaped wildcards, enum checks)
 * so it runs on MySQL 8.0 and SQLite (tests). Day boundaries are always clinic
 * timezone instants (`startOfDay` inclusive, `endOfDay` exclusive).
 */
final class SaleQueries
{
    /**
     * Shared WHERE builder for list, export and void inbox. Values must already
     * be sanitized by the caller (§7 in the spec: unknown/out-of-range params
     * dropped).
     *
     * @param  array{from?: ?string, to?: ?string, patient?: ?string, serviceId?: ?string, doctorId?: ?string, status?: ?string, paymentMethod?: ?string, createdBy?: ?string}  $filters
     */
    public static function buildSaleWhere(array $filters, string $tz): Builder
    {
        $query = Sale::query();

        if (! empty($filters['from'])) {
            $query->where('sale_date', '>=', ClinicTime::startOfDay($filters['from'], $tz));
        }

        if (! empty($filters['to'])) {
            $query->where('sale_date', '<', ClinicTime::endOfDay($filters['to'], $tz));
        }

        $patient = isset($filters['patient']) ? trim($filters['patient']) : '';
        if ($patient !== '') {
            $escaped = self::escapeLike($patient);
            $query->where(function (Builder $inner) use ($escaped, $patient): void {
                $inner->whereHas('patient', function (Builder $p) use ($escaped): void {
                    $p->where('full_name', 'like', '%'.$escaped.'%');
                });

                foreach (Phone::phoneSearchVariants($patient) ?? [] as $variant) {
                    $inner->orWhereHas('patient', function (Builder $p) use ($variant): void {
                        $p->where('phone_e164', 'like', '%'.self::escapeLike($variant).'%');
                    });
                }
            });
        }

        if (! empty($filters['serviceId'])) {
            $query->whereHas('lines', fn (Builder $q) => $q->where('service_id', $filters['serviceId']));
        }

        if (! empty($filters['doctorId'])) {
            $query->where('doctor_id', $filters['doctorId']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['paymentMethod'])) {
            $query->where('payment_method', $filters['paymentMethod']);
        }

        if (! empty($filters['createdBy'])) {
            $query->where('created_by', $filters['createdBy']);
        }

        return $query;
    }

    /**
     * RECEPTION may only see their own sales from the current clinic day; the
     * OWNER's filters pass through untouched.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function scopeToActor(array $filters, User $actor, string $todayKey): array
    {
        if (Roles::canSeeAllSales($actor->role)) {
            return $filters;
        }

        return array_merge($filters, [
            'createdBy' => $actor->id,
            'from' => $todayKey,
            'to' => $todayKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pageCount: int, sumTotal: string}
     */
    public static function listSales(array $filters, string $tz): array
    {
        $base = self::buildSaleWhere($filters, $tz);
        $total = (clone $base)->count();

        // When filtering by a single service, sum only that service's line
        // totals across matching sales (a sale can contain several services;
        // its whole total must not be credited to one service).
        if (! empty($filters['serviceId'])) {
            $whereWithoutService = self::buildSaleWhere(
                array_merge($filters, ['serviceId' => null]),
                $tz,
            );
            $sum = SaleLine::query()
                ->where('service_id', $filters['serviceId'])
                ->whereIn('sale_id', (clone $whereWithoutService)->select('id'))
                ->sum('line_total');
            $sumTotal = Money::toDecimalString($sum ?? '0');
        } else {
            $sum = (clone $base)->sum('total');
            $sumTotal = Money::toDecimalString($sum ?? '0');
        }

        $pageCount = max(1, (int) ceil($total / Constants::SALES_PAGE_SIZE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $pageCount);

        $rows = (clone $base)
            ->with(['patient:id,full_name', 'doctor:id,full_name'])
            ->withCount('lines')
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->skip(($page - 1) * Constants::SALES_PAGE_SIZE)
            ->take(Constants::SALES_PAGE_SIZE)
            ->get();

        $names = self::creatorNames($rows);

        return [
            'rows' => $rows->map(fn (Sale $row): array => self::serializeListRow($row, $names))->all(),
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'sumTotal' => $sumTotal,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public static function listSalesForExport(array $filters, string $tz): array
    {
        $rows = self::buildSaleWhere($filters, $tz)
            ->with(['patient:id,full_name', 'doctor:id,full_name'])
            ->withCount('lines')
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->take(Constants::SALES_EXPORT_CAP)
            ->get();

        $names = self::creatorNames($rows);

        return $rows->map(fn (Sale $row): array => self::serializeListRow($row, $names))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getSale(string $id): ?array
    {
        $sale = Sale::query()
            ->with([
                'patient:id,full_name,phone_e164',
                'doctor:id,full_name',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.service:id,category,receipt_label_en,receipt_label_am',
            ])
            ->find($id);

        if ($sale === null) {
            return null;
        }

        $userIds = array_values(array_unique(array_filter([
            $sale->created_by,
            $sale->void_requested_by,
            $sale->voided_by,
        ])));
        $names = $userIds === []
            ? collect()
            : User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        return [
            'id' => $sale->id,
            'receiptNumber' => $sale->receipt_number,
            'saleDate' => $sale->sale_date,
            'status' => $sale->status,
            'paymentMethod' => $sale->payment_method,
            'total' => Money::toDecimalString($sale->total),
            'patient' => $sale->patient === null ? null : [
                'id' => $sale->patient->id,
                'fullName' => $sale->patient->full_name,
                'phoneE164' => $sale->patient->phone_e164,
            ],
            'doctor' => $sale->doctor === null ? null : [
                'id' => $sale->doctor->id,
                'fullName' => $sale->doctor->full_name,
            ],
            'lines' => $sale->lines->map(fn (SaleLine $line): array => [
                'id' => $line->id,
                'serviceId' => $line->service_id,
                'serviceNameSnapshot' => $line->service_name_snapshot,
                'category' => $line->service?->category ?? '',
                'receiptLabelEn' => $line->service?->receipt_label_en,
                'receiptLabelAm' => $line->service?->receipt_label_am,
                'qty' => (int) $line->qty,
                'unitPrice' => Money::toDecimalString($line->unit_price),
                'lineTotal' => Money::toDecimalString($line->line_total),
            ])->all(),
            'voidRequestedBy' => $sale->void_requested_by,
            'voidRequestedByName' => $sale->void_requested_by === null ? null : $names->get($sale->void_requested_by),
            'voidRequestReason' => $sale->void_request_reason,
            'voidedBy' => $sale->voided_by,
            'voidedByName' => $sale->voided_by === null ? null : $names->get($sale->voided_by),
            'voidReason' => $sale->void_reason,
            'voidedAt' => $sale->voided_at,
            'createdBy' => $sale->created_by,
            'createdByName' => $names->get($sale->created_by),
            'confirmedAt' => $sale->confirmed_at,
            'createdAt' => $sale->created_at,
            'updatedAt' => $sale->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listVoidRequests(): array
    {
        $sales = Sale::query()
            ->where('status', Sale::STATUS_CONFIRMED)
            ->whereNotNull('void_requested_by')
            ->with('patient:id,full_name')
            ->orderBy('updated_at')
            ->get();

        $ids = array_values(array_unique(array_filter($sales->pluck('void_requested_by')->all())));
        $names = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $sales->map(fn (Sale $sale): array => [
            'id' => $sale->id,
            'receiptNumber' => $sale->receipt_number,
            'saleDate' => $sale->sale_date,
            'total' => Money::toDecimalString($sale->total),
            'patientName' => $sale->patient?->full_name,
            'requestedBy' => $sale->void_requested_by,
            'requestedByName' => $sale->void_requested_by === null ? null : $names->get($sale->void_requested_by),
            'reason' => $sale->void_request_reason,
        ])->all();
    }

    /** Patient detail badge: how many sales this patient has. */
    public static function countPatientSales(string $patientId): int
    {
        return Sale::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [Sale::STATUS_CONFIRMED, Sale::STATUS_VOIDED])
            ->count();
    }

    /**
     * Patient detail "Sales" tab: this patient's sales (newest first).
     *
     * @return array{rows: list<array<string, mixed>>, count: int, confirmedTotal: string}
     */
    public static function listPatientSales(string $patientId, int $limit = 50): array
    {
        $rows = Sale::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [Sale::STATUS_CONFIRMED, Sale::STATUS_VOIDED])
            ->orderByDesc('sale_date')
            ->take($limit)
            ->get();

        $count = Sale::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [Sale::STATUS_CONFIRMED, Sale::STATUS_VOIDED])
            ->count();

        $sum = Sale::query()
            ->where('patient_id', $patientId)
            ->where('status', Sale::STATUS_CONFIRMED)
            ->sum('total');

        return [
            'rows' => $rows->map(fn (Sale $row): array => [
                'id' => $row->id,
                'receiptNumber' => $row->receipt_number,
                'saleDate' => $row->sale_date,
                'status' => $row->status,
                'paymentMethod' => $row->payment_method,
                'total' => Money::toDecimalString($row->total),
            ])->all(),
            'count' => $count,
            'confirmedTotal' => Money::toDecimalString($sum ?? '0'),
        ];
    }

    /**
     * Active staff options for the doctor picker.
     *
     * @return Collection<int, Staff>
     */
    public static function listDoctorOptions(): Collection
    {
        return Staff::query()
            ->where('active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);
    }

    /**
     * Audit trail for one sale (newest first, max 100).
     *
     * @return list<array<string, mixed>>
     */
    public static function listSaleAudit(string $saleId): array
    {
        $rows = AuditLog::query()
            ->where('entity', 'Sale')
            ->where('entity_id', $saleId)
            ->orderByDesc('created_at')
            ->take(100)
            ->get(['id', 'action', 'user_id', 'created_at']);

        $ids = array_values(array_unique(array_filter($rows->pluck('user_id')->all())));
        $names = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $rows->map(fn (AuditLog $row): array => [
            'id' => $row->id,
            'action' => $row->action,
            'userId' => $row->user_id,
            'userName' => $row->user_id === null ? null : $names->get($row->user_id),
            'createdAt' => $row->created_at,
        ])->all();
    }

    /**
     * Sales (CONFIRMED + VOIDED) with line categories for the revenue report.
     * Callers pass UTC instants for the widest range they need (current +
     * previous periods); the report splits by clinic-tz day itself.
     *
     * @return list<array<string, mixed>>
     */
    public static function listRevenueSales(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = Sale::query()
            ->whereIn('status', [Sale::STATUS_CONFIRMED, Sale::STATUS_VOIDED])
            ->where('sale_date', '>=', $from)
            ->where('sale_date', '<', $to)
            ->with([
                'doctor:id,full_name',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.service:id,category',
            ])
            ->get();

        return $rows->map(fn (Sale $sale): array => [
            'id' => $sale->id,
            'saleDate' => $sale->sale_date,
            'status' => $sale->status,
            'total' => Money::toDecimalString($sale->total),
            'paymentMethod' => $sale->payment_method,
            'doctorName' => $sale->doctor?->full_name,
            'lines' => $sale->lines->map(fn (SaleLine $line): array => [
                'serviceName' => $line->service_name_snapshot,
                'category' => $line->service?->category ?? '',
                'qty' => (int) $line->qty,
                'lineTotal' => Money::toDecimalString($line->line_total),
            ])->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, Sale>  $rows
     * @return \Illuminate\Support\Collection<string, string>
     */
    private static function creatorNames(Collection $rows): \Illuminate\Support\Collection
    {
        $ids = array_values(array_unique($rows->pluck('created_by')->all()));

        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * @param  \Illuminate\Support\Collection<string, string>  $names
     * @return array<string, mixed>
     */
    private static function serializeListRow(Sale $row, \Illuminate\Support\Collection $names): array
    {
        return [
            'id' => $row->id,
            'receiptNumber' => $row->receipt_number,
            'saleDate' => $row->sale_date,
            'status' => $row->status,
            'paymentMethod' => $row->payment_method,
            'total' => Money::toDecimalString($row->total),
            'createdBy' => $row->created_by,
            'createdByName' => $names->get($row->created_by),
            'patient' => $row->patient === null ? null : [
                'id' => $row->patient->id,
                'fullName' => $row->patient->full_name,
            ],
            'doctor' => $row->doctor === null ? null : [
                'id' => $row->doctor->id,
                'fullName' => $row->doctor->full_name,
            ],
            'lineCount' => (int) ($row->lines_count ?? 0),
        ];
    }

    /** Escapes LIKE wildcards so a literal `%` never matches everything. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
