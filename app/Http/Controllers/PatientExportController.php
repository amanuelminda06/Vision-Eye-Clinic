<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Patients\Csv;
use App\Support\Patients\PatientImport;
use App\Support\Patients\PatientQueries;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patient CSV/XLSX export + import template, ported from
 * src/app/api/patients/export/route.ts and .../template/route.ts.
 *
 * Both are OWNER+RECEPTION surfaces (route gate) and both stream an
 * attachment with `Cache-Control: no-store`. The export records an EXPORTED
 * audit; the template does not.
 */
class PatientExportController extends Controller
{
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'systemId',
        'idNo',
        'fullName',
        'phone',
        'sex',
        'age',
        'preferredLanguage',
        'tags',
        'doNotMessage',
        'active',
        'createdAt',
    ];

    /** GET /patients/export?format=csv|xlsx — filtered download. */
    public function export(Request $request): Response
    {
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        $dateFromRaw = mb_substr((string) $request->query('dateFrom', ''), 0, 10);
        $dateToRaw = mb_substr((string) $request->query('dateTo', ''), 0, 10);
        $statusRaw = (string) $request->query('status', '');

        $filters = [
            'q' => mb_substr((string) $request->query('q', ''), 0, 100) ?: null,
            'idNo' => mb_substr((string) $request->query('idNo', ''), 0, 32) ?: null,
            'systemId' => mb_substr((string) $request->query('systemId', ''), 0, 6) ?: null,
            'dateFrom' => preg_match(self::DATE_RE, $dateFromRaw) === 1 ? $dateFromRaw : null,
            'dateTo' => preg_match(self::DATE_RE, $dateToRaw) === 1 ? $dateToRaw : null,
            'status' => in_array($statusRaw, ['true', 'false'], true) ? $statusRaw : '',
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
        ];

        $rows = PatientQueries::listForExport(array_filter($filters, static fn ($v) => $v !== null));

        $actor = $request->user();
        Audit::write('patient', 'export', 'EXPORTED', null, array_merge(
            array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
            ['format' => $format],
        ), ['id' => $actor->id, 'name' => $actor->name]);

        $cells = [];
        foreach ($rows as $row) {
            $cells[] = [
                $row->id,
                $row->system_id ?? '',
                $row->id_no ?? '',
                $row->full_name,
                $row->phone_e164,
                $row->sex ?? '',
                $row->age ?? '',
                $row->preferred_language,
                $row->tags->pluck('name')->implode('; '),
                (bool) $row->do_not_message,
                (bool) $row->active,
                $row->created_at?->toIso8601String() ?? '',
            ];
        }

        $filename = 'patients-'.gmdate('Y-m-d').'.csv';

        if ($format === 'xlsx') {
            $body = Xlsx::toXlsx([
                'name' => 'patients',
                'headers' => self::COLUMNS,
                'rows' => $cells,
            ]);

            return response($body, 200, self::downloadHeaders(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                preg_replace('/\.csv$/', '.xlsx', $filename),
            ));
        }

        return response(Csv::toCsv(self::COLUMNS, $cells), 200, self::downloadHeaders(
            'text/csv; charset=utf-8',
            $filename,
        ));
    }

    /** GET /patients/template — CSV import template (columns + example row). */
    public function template(): Response
    {
        $csv = Csv::toCsv(PatientImport::IMPORT_COLUMNS, [
            ['Example Patient', '0912345678', 'MALE', '30', 'glasses', 'en'],
        ]);

        return response($csv, 200, self::downloadHeaders(
            'text/csv; charset=utf-8',
            'patients-template.csv',
        ));
    }

    /** @return array<string, string> */
    private static function downloadHeaders(string $contentType, string $filename): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ];
    }
}
