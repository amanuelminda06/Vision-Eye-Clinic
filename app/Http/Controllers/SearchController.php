<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Sale;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global type-ahead search, ported from src/app/api/search/route.ts.
 *
 * OWNER+RECEPTION only (route gate). `q` is trimmed and capped to 100 chars;
 * fewer than 2 chars returns empty groups. The patient branch only runs at 3+
 * chars, is restricted to active patients, and matches name (insensitive) or a
 * phone search variant — 6 hits, ordered by name.
 *
 * The sales branch matches receipt number, patient name or a phone variant
 * (newest first). Every group is finally sorted by title with a locale-aware
 * comparison, mirroring the legacy `hitSorter`. The appointments group is
 * filled in by slice 5; until then it stays an empty array (the response shape
 * is identical, so the front-end needs no change).
 */
class SearchController extends Controller
{
    private const MAX_QUERY = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, self::MAX_QUERY);

        $empty = ['patients' => [], 'sales' => [], 'appointments' => []];

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json($empty)->header('Cache-Control', 'no-store');
        }

        $patients = [];
        $sales = [];

        if (mb_strlen($q, 'UTF-8') >= 3) {
            $query = Patient::query()
                ->where('active', true)
                ->where(function ($inner) use ($q): void {
                    $inner->where('full_name', 'like', '%'.self::escapeLike($q).'%');

                    foreach (Phone::phoneSearchVariants($q) ?? [] as $variant) {
                        $inner->orWhere('phone_e164', 'like', '%'.self::escapeLike($variant).'%');
                    }
                })
                ->orderBy('full_name')
                ->take(6)
                ->get();

            foreach ($query as $patient) {
                $patients[] = [
                    'id' => $patient->id,
                    'title' => $patient->full_name,
                    'subtitle' => $patient->phone_e164,
                    'href' => "/patients/{$patient->id}",
                ];
            }
        }

        $tz = Settings::timezone();

        $saleRows = Sale::query()
            ->with('patient:id,full_name')
            ->where(function ($inner) use ($q): void {
                $inner->where('receipt_number', 'like', '%'.self::escapeLike($q).'%')
                    ->orWhereHas('patient', function ($p) use ($q): void {
                        $p->where('full_name', 'like', '%'.self::escapeLike($q).'%');
                    });

                foreach (Phone::phoneSearchVariants($q) ?? [] as $variant) {
                    $inner->orWhereHas('patient', function ($p) use ($variant): void {
                        $p->where('phone_e164', 'like', '%'.self::escapeLike($variant).'%');
                    });
                }
            })
            ->orderByDesc('sale_date')
            ->take(6)
            ->get();

        foreach ($saleRows as $sale) {
            $sales[] = [
                'id' => $sale->id,
                'title' => $sale->receipt_number,
                'subtitle' => implode(' · ', [
                    $sale->patient?->full_name ?? '',
                    Money::toDecimalString($sale->total),
                    ClinicTime::formatInTz($sale->sale_date, $tz),
                ]),
                'href' => "/sales/{$sale->id}",
            ];
        }

        return response()->json([
            'patients' => self::sortHits($patients),
            'sales' => self::sortHits($sales),
            'appointments' => [],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Locale-aware title sort (legacy `hitSorter`).
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private static function sortHits(array $hits): array
    {
        usort($hits, fn (array $a, array $b): int => strcoll((string) $a['title'], (string) $b['title']));

        return $hits;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
