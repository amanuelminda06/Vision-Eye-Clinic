<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Appointments\ApptQueries;
use App\Support\Appointments\Constants;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Patients\Csv;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filtered appointment export (OWNER + RECEPTION), ported from
 * src/app/api/appointments/export/route.ts.
 *
 * Same filter params as the board; the download is audited as EXPORTED.
 */
class AppointmentExportController extends Controller
{
    /** @var list<string> */
    private const COLUMNS = [
        'id', 'startsAt', 'endsAt', 'patient', 'patientPhone',
        'doctor', 'service', 'status', 'createdFrom', 'notes',
    ];

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $format = (string) $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';

        $fromRaw = mb_substr((string) $request->query('from', ''), 0, 10);
        $from = preg_match(Constants::DATE_RE, $fromRaw) === 1 ? $fromRaw : null;
        $toRaw = mb_substr((string) $request->query('to', ''), 0, 10);
        $to = preg_match(Constants::DATE_RE, $toRaw) === 1 ? $toRaw : null;

        $doctorId = mb_substr((string) $request->query('doctorId', ''), 0, 64);
        $patientId = mb_substr((string) $request->query('patientId', ''), 0, 64);
        $status = mb_substr((string) $request->query('status', ''), 0, 32);

        $params = [];
        if ($from !== null) {
            $params['from'] = ClinicTime::combineDateAndTime($from, '00:00', $tz);
        }
        if ($to !== null) {
            $params['to'] = ClinicTime::combineDateAndTime($to, '00:00', $tz);
        }
        if ($doctorId !== '') {
            $params['doctorId'] = $doctorId;
        }
        if ($patientId !== '') {
            $params['patientId'] = $patientId;
        }
        if ($status !== '') {
            $params['status'] = $status;
        }

        $rows = ApptQueries::listAppointments($params);

        Audit::write('appointment', 'export', 'EXPORTED', null, array_merge(
            array_filter([
                'from' => $from,
                'to' => $to,
            ], static fn ($value): bool => $value !== null),
            ['format' => $format],
        ), ['id' => $actor->id, 'name' => $actor->name]);

        $cells = array_map(fn (array $row): array => [
            $row['id'],
            ClinicTime::formatInTz($row['startsAt'], $tz),
            ClinicTime::formatInTz($row['endsAt'], $tz),
            $row['patientName'],
            $row['patientPhoneE164'] ?? '',
            $row['doctorName'],
            $row['serviceName'] ?? '',
            $row['status'],
            $row['createdFrom'],
            $row['notes'] ?? '',
        ], $rows);

        $filename = 'appointments-'.gmdate('Y-m-d').'.csv';

        if ($format === 'xlsx') {
            $body = Xlsx::toXlsx([[
                'name' => 'appointments',
                'headers' => self::COLUMNS,
                'rows' => $cells,
            ]]);

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
}
