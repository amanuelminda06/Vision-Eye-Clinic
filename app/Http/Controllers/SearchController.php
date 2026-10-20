<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\Phone;
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
 * The sales and appointments groups are filled in by slices 3 and 5; until
 * those models have their queries, they return empty arrays (the response shape
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

        return response()->json([
            'patients' => $patients,
            'sales' => [],
            'appointments' => [],
        ])->header('Cache-Control', 'no-store');
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
