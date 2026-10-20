<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The daily sales summary (OWNER only) — printable, with the previous/next
 * day navigation.
 */
class DailySummaryController extends Controller
{
    /** GET /dashboard/daily?day=YYYY-MM-DD */
    public function index(Request $request): Response
    {
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        $dayRaw = (string) $request->query('day', '');
        $dayKey = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayRaw) === 1 ? $dayRaw : $todayKey;

        $from = ClinicTime::combineDateAndTime($dayKey, '00:00', $tz);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dayKey, 1), '00:00', $tz);

        $sales = Sale::query()
            ->with(['patient:id,full_name', 'doctor:id,full_name'])
            ->where('sale_date', '>=', $from)
            ->where('sale_date', '<', $to)
            ->orderBy('sale_date')
            ->get();

        $confirmed = $sales->filter(fn (Sale $sale): bool => (string) $sale->status === Sale::STATUS_CONFIRMED);
        $voided = $sales->filter(fn (Sale $sale): bool => (string) $sale->status === Sale::STATUS_VOIDED);

        return response()->view('dashboard-daily', [
            'dayKey' => $dayKey,
            'todayKey' => $todayKey,
            'prevKey' => ClinicTime::dateKeyAdd($dayKey, -1),
            'nextKey' => ClinicTime::dateKeyAdd($dayKey, 1),
            'sales' => $sales->map(fn (Sale $sale): array => [
                'id' => $sale->id,
                'receiptNumber' => (string) $sale->receipt_number,
                'time' => ClinicTime::formatInTz($sale->sale_date, $tz),
                'patientName' => $sale->patient?->full_name ?? '',
                'doctorName' => $sale->doctor?->full_name ?? '',
                'status' => (string) $sale->status,
                'total' => Money::toDecimalString($sale->total),
            ])->all(),
            'confirmedTotal' => Money::toDecimalString($confirmed->sum('total')),
            'confirmedCount' => $confirmed->count(),
            'voidedTotal' => Money::toDecimalString($voided->sum('total')),
            'voidedCount' => $voided->count(),
            'currency' => Settings::currency(),
            'timezone' => $tz,
        ]);
    }
}
