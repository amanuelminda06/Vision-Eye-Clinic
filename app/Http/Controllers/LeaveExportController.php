<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\Constants;
use App\Support\Hr\HrQueries;
use App\Support\Hr\HrRoles;
use App\Support\Hr\Shifts;
use App\Support\Patients\Csv;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave CSV/XLSX export, ported from src/app/api/leave/export/route.ts.
 *
 * OWNER + RECEPTION get the team history; STAFF always get only their own
 * rows. A valid from/to pair switches to the team range query. The download is
 * audited as EXPORTED.
 */
class LeaveExportController extends Controller
{
    /** @var list<string> */
    private const COLUMNS = [
        'id', 'staff', 'type', 'startDate', 'endDate', 'halfDay',
        'reason', 'status', 'decisionNote', 'createdAt',
    ];

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $format = (string) $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';

        $statusRaw = strtoupper((string) $request->query('status', ''));
        $status = Constants::isLeaveStatus($statusRaw) ? $statusRaw : '';

        $fromRaw = mb_substr((string) $request->query('from', ''), 0, 10);
        $toRaw = mb_substr((string) $request->query('to', ''), 0, 10);
        $hasRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1;

        if (! HrRoles::canViewTeamLeave($actor->role)) {
            // STAFF only ever sees their own rows.
            $staffId = HrQueries::getUserStaffId($actor->id);
            $rows = $staffId === null ? [] : HrQueries::listLeaveForStaff($staffId);
        } elseif ($hasRange) {
            $rows = HrQueries::listTeamLeaveInRange($fromRaw, $toRaw);
        } else {
            $rows = [];
            foreach (Constants::LEAVE_STATUSES as $leaveStatus) {
                if ($status !== '' && $status !== $leaveStatus) {
                    continue;
                }

                $rows = array_merge($rows, HrQueries::listLeaveByStatus($leaveStatus));
            }
        }

        usort($rows, fn (array $a, array $b): int => ($b['createdAt']?->getTimestamp() ?? 0) <=> ($a['createdAt']?->getTimestamp() ?? 0));

        Audit::write('leaveRequest', 'export', 'EXPORTED', null, [
            'status' => $status === '' ? 'all' : $status,
            'format' => $format,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        $cells = array_map(fn (array $row): array => [
            $row['id'],
            $row['staffName'],
            $row['type'],
            Shifts::dateColumnToKey($row['startDate']),
            Shifts::dateColumnToKey($row['endDate']),
            $row['halfDay'] ? 'true' : 'false',
            $row['reason'] ?? '',
            $row['status'],
            $row['decisionNote'] ?? '',
            $row['createdAt'] === null ? '' : ClinicTime::formatInTz($row['createdAt'], $tz),
        ], $rows);

        $filename = 'leave-'.gmdate('Y-m-d').'.csv';

        if ($format === 'xlsx') {
            $body = Xlsx::toXlsx([[
                'name' => 'leave',
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
