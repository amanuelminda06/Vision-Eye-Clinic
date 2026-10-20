<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Patients\Csv;
use App\Support\Sales\Constants;
use App\Support\Sales\SaleQueries;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales CSV/XLSX export, ported from src/app/api/sales/export/route.ts.
 *
 * OWNER gets the full filtered list; RECEPTION is scoped to their own sales of
 * the current clinic day (scopeToActor overrides the caller's from/to). Capped
 * at SALES_EXPORT_CAP rows and audited as EXPORTED.
 */
class SaleExportController extends Controller
{
    /** @var list<string> */
    private const COLUMNS = [
        'receiptNumber', 'saleDate', 'patient', 'doctor',
        'paymentMethod', 'status', 'lines', 'total', 'createdBy',
    ];

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        $filters = SaleQueries::scopeToActor($this->readFilters($request), $actor, $todayKey);
        $rows = SaleQueries::listSalesForExport($filters, $tz);

        $format = (string) $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $filename = 'sales-'.gmdate('Y-m-d').'.csv';

        Audit::write('sale', 'export', 'EXPORTED', null, array_merge(
            $filters,
            ['format' => $format],
        ), ['id' => $actor->id, 'name' => $actor->name]);

        $tableRows = array_map(fn (array $row): array => [
            $row['receiptNumber'],
            $row['saleDate']?->toIso8601String() ?? '',
            $row['patient']['fullName'] ?? '',
            $row['doctor']['fullName'] ?? '',
            $row['paymentMethod'],
            $row['status'],
            $row['lineCount'],
            $row['total'],
            $row['createdByName'] ?? '',
        ], $rows);

        if ($format === 'xlsx') {
            $bytes = Xlsx::toXlsx([[
                'name' => 'sales',
                'headers' => self::COLUMNS,
                'rows' => $tableRows,
            ]]);

            return response($bytes, 200, self::downloadHeaders(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                preg_replace('/\.csv$/', '.xlsx', $filename),
            ));
        }

        return response(Csv::toCsv(self::COLUMNS, $tableRows), 200, self::downloadHeaders(
            'text/csv; charset=utf-8',
            $filename,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function downloadHeaders(string $contentType, string $filename): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readFilters(Request $request): array
    {
        $dateRe = '/^\d{4}-\d{2}-\d{2}$/';

        $from = mb_substr((string) $request->query('from', ''), 0, 10);
        $from = preg_match($dateRe, $from) === 1 ? $from : '';
        $to = mb_substr((string) $request->query('to', ''), 0, 10);
        $to = preg_match($dateRe, $to) === 1 ? $to : '';

        $statusRaw = (string) $request->query('status', '');
        $paymentRaw = (string) $request->query('paymentMethod', '');

        return [
            'from' => $from,
            'to' => $to,
            'patient' => mb_substr((string) $request->query('patient', ''), 0, Constants::MAX_PATIENT_QUERY),
            'serviceId' => mb_substr((string) $request->query('serviceId', ''), 0, 64),
            'doctorId' => mb_substr((string) $request->query('doctorId', ''), 0, 64),
            'status' => Constants::isSaleStatus($statusRaw) ? $statusRaw : '',
            'paymentMethod' => Constants::isPaymentMethod($paymentRaw) ? $paymentRaw : '',
        ];
    }
}
