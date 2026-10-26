<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Models\Patient;
use App\Models\Tag;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Patient list/detail queries, ported from src/modules/patients/queries.ts.
 *
 * Everything here is portable SQL (LIKE with escaped wildcards) so the same
 * code runs on MySQL 8.0 and SQLite (tests). The `q` search is
 * case-insensitive on MySQL (utf8mb4 default collation) and ASCII
 * case-insensitive on SQLite — both accept the legacy behaviour for names.
 */
final class PatientQueries
{
    public const PAGE_SIZE = 20;

    /** @var list<string> */
    public const SORTS = ['fullName', 'age', 'createdAt'];

    public const EXPORT_ROW_CAP = 5000;

    /** Whitelist a `?sort=` value (legacy sortFrom). Also accepts DB column names. */
    public static function sortColumn(?string $value): string
    {
        $map = [
            'fullName' => 'full_name',
            'age' => 'age',
            'createdAt' => 'created_at',
            // The list screen echoes back the resolved column name in its
            // export links; accept it so a sorted export keeps its sort.
            'full_name' => 'full_name',
            'created_at' => 'created_at',
        ];

        return $map[$value ?? ''] ?? 'full_name';
    }

    /** Whitelist a `?dir=` value (legacy dirFrom) — anything but 'desc' is 'asc'. */
    public static function sortDir(?string $value): string
    {
        return $value === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Shared WHERE builder for the list screen, CSV export and export link.
     *
     * @param  array{q?: string, idNo?: string, systemId?: string, dateFrom?: string, dateTo?: string, status?: string}  $filters
     */
    public static function buildWhere(array $filters): Builder
    {
        $query = Patient::query();

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $inner) use ($q): void {
                $inner->where('full_name', 'like', '%'.self::escapeLike($q).'%');

                foreach (Phone::phoneSearchVariants($q) ?? [] as $variant) {
                    $inner->orWhere('phone_e164', 'like', '%'.self::escapeLike($variant).'%');
                }

                $inner->orWhere('system_id', 'like', '%'.self::escapeLike($q).'%');
                $inner->orWhere('id_no', 'like', '%'.self::escapeLike($q).'%');
            });
        }

        $systemId = trim((string) ($filters['systemId'] ?? ''));
        if ($systemId !== '') {
            $query->where('system_id', 'like', self::escapeLike($systemId));
        }

        $idNo = trim((string) ($filters['idNo'] ?? ''));
        if ($idNo !== '') {
            $query->where('id_no', 'like', self::escapeLike($idNo));
        }

        $dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '') {
            $query->where('created_at', '>=', ClinicTime::startOfDay($dateFrom)->format('Y-m-d H:i:s'));
        }

        $dateTo = trim((string) ($filters['dateTo'] ?? ''));
        if ($dateTo !== '') {
            $query->where('created_at', '<', ClinicTime::endOfDay($dateTo)->format('Y-m-d H:i:s'));
        }

        if (($filters['status'] ?? '') === 'true') {
            $query->where('active', true);
        } elseif (($filters['status'] ?? '') === 'false') {
            $query->where('active', false);
        }

        return $query;
    }

    /**
     * Paginated list (legacy listPatients): tags eager-loaded, name asc.
     *
     * @param  array{q?: string, idNo?: string, dateFrom?: string, dateTo?: string, status?: string, sort?: string, dir?: string, page?: int}  $filters
     * @return array{rows: Collection<int, Patient>, total: int, page: int, pageCount: int}
     */
    public static function list(array $filters): array
    {
        $total = self::buildWhere($filters)->count();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $pageCount);

        $rows = self::buildWhere($filters)
            ->with(['tags' => fn ($relation) => $relation->orderBy('name')])
            ->orderBy(self::sortColumn($filters['sort'] ?? null), self::sortDir($filters['dir'] ?? null))
            ->orderBy('id')
            ->skip(($page - 1) * self::PAGE_SIZE)
            ->take(self::PAGE_SIZE)
            ->get();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pageCount' => $pageCount];
    }

    /**
     * Filtered export without pagination (capped).
     *
     * @param  array{q?: string, idNo?: string, dateFrom?: string, dateTo?: string, status?: string, sort?: string, dir?: string}  $filters
     * @return Collection<int, Patient>
     */
    public static function listForExport(array $filters): Collection
    {
        return self::buildWhere($filters)
            ->with(['tags' => fn ($relation) => $relation->orderBy('name')])
            ->orderBy(self::sortColumn($filters['sort'] ?? null), self::sortDir($filters['dir'] ?? null))
            ->orderBy('id')
            ->take(self::EXPORT_ROW_CAP)
            ->get();
    }

    /** Detail loader: tags (name asc) + the creator's name (no FK, raw id). */
    public static function find(string $id): ?Patient
    {
        $patient = Patient::with(['tags' => fn ($relation) => $relation->orderBy('name')])->find($id);

        if ($patient === null) {
            return null;
        }

        $patient->setAttribute(
            'created_by_name',
            $patient->created_by !== null
                ? User::whereKey($patient->created_by)->value('name')
                : null,
        );

        return $patient;
    }

    /** Phone lookup for duplicate blocking (legacy findPatientByPhone). */
    public static function findByPhone(string $phoneE164): ?Patient
    {
        return Patient::where('phone_e164', $phoneE164)->first();
    }

    /**
     * Very similar name + exactly the same age (never blocks, only warns).
     * A null age means nothing to compare, so there are no candidates.
     *
     * @return list<array{id: string, fullName: string}>
     */
    public static function findSimilar(string $fullName, ?int $age, ?string $excludeId = null, int $limit = 5): array
    {
        if ($age === null) {
            return [];
        }

        $query = Patient::query()
            ->where('age', $age)
            ->take(100);

        if ($excludeId !== null && $excludeId !== '') {
            $query->where('id', '!=', $excludeId);
        }

        $matches = [];

        foreach ($query->get() as $candidate) {
            if (Duplicates::isSimilarName((string) $candidate->full_name, $fullName)) {
                $matches[] = ['id' => $candidate->id, 'fullName' => $candidate->full_name];

                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        return $matches;
    }

    /** All tag names for the editor's datalist, alphabetical. */
    public static function listAllTags(): Collection
    {
        return Tag::orderBy('name')->get();
    }

    /** Escape LIKE wildcards so user fragments match literally. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
