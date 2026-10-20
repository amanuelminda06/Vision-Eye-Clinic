<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Patients\Csv;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The audit viewer (OWNER only) and its CSV/XLSX export.
 */
class AuditController extends Controller
{
    public const PAGE_SIZE = 25;

    public const EXPORT_ROW_CAP = 5000;

    /** GET /audit */
    public function index(Request $request): Response
    {
        $filters = $this->readFilters($request);

        $query = $this->buildQuery($filters);

        $total = (clone $query)->count();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) $request->query('page', 1)), $pageCount);

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(($page - 1) * self::PAGE_SIZE)
            ->take(self::PAGE_SIZE)
            ->get();

        $userIds = array_values(array_unique(array_filter(
            $rows->pluck('user_id')->all(),
            fn ($id): bool => $id !== null && $id !== '',
        )));

        $names = $userIds === []
            ? collect()
            : User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        return response()->view('audit.index', [
            'rows' => $rows->map(fn (AuditLog $row): array => [
                'id' => $row->id,
                'createdAt' => $row->created_at,
                'userName' => $row->user_id === null ? null : $names->get($row->user_id),
                'entity' => (string) $row->entity,
                'entityId' => (string) $row->entity_id,
                'action' => (string) $row->action,
                'ip' => $row->ip,
                'oldJson' => $row->old_json,
                'newJson' => $row->new_json,
            ])->all(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
        ]);
    }

    /** GET /audit/export — the CSV/XLSX download. */
    public function export(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $format = (string) $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $filters = $this->readFilters($request);

        $rows = $this->buildQuery($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(self::EXPORT_ROW_CAP)
            ->get();

        $userIds = array_values(array_unique(array_filter(
            $rows->pluck('user_id')->all(),
            fn ($id): bool => $id !== null && $id !== '',
        )));

        $users = $userIds === []
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email']);

        $byId = $users->keyBy('id');

        Audit::write('auditLog', 'export', 'EXPORTED', null, array_merge(
            array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''),
            ['format' => $format],
        ), ['id' => $actor->id, 'name' => $actor->name]);

        $cells = $rows->map(function (AuditLog $row) use ($byId, $tz): array {
            $user = $row->user_id === null ? null : $byId->get($row->user_id);

            return [
                ClinicTime::formatInTz($row->created_at, $tz),
                $user?->name ?? '',
                $user?->email ?? '',
                (string) $row->entity,
                (string) $row->entity_id,
                (string) $row->action,
                $row->ip ?? '',
                $row->old_json === null ? '' : (string) json_encode($row->old_json),
                $row->new_json === null ? '' : (string) json_encode($row->new_json),
            ];
        })->all();

        $headers = ['time', 'user', 'email', 'entity', 'entityId', 'action', 'ip', 'old', 'new'];
        $filename = 'audit-'.gmdate('Y-m-d').'.csv';

        if ($format === 'xlsx') {
            $body = Xlsx::toXlsx([[
                'name' => 'audit',
                'headers' => $headers,
                'rows' => $cells,
            ]]);

            return response($body, 200, self::downloadHeaders(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                preg_replace('/\.csv$/', '.xlsx', $filename),
            ));
        }

        return response(Csv::toCsv($headers, $cells), 200, self::downloadHeaders(
            'text/csv; charset=utf-8',
            $filename,
        ));
    }

    /**
     * @return array{userId: ?string, entity: ?string, action: ?string, from: ?string, to: ?string}
     */
    private function readFilters(Request $request): array
    {
        $userId = mb_substr((string) $request->query('userId', ''), 0, 64);
        $entity = mb_substr((string) $request->query('entity', ''), 0, 64);
        $action = mb_substr((string) $request->query('action', ''), 0, 64);

        $fromRaw = mb_substr((string) $request->query('from', ''), 0, 10);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1 ? $fromRaw : null;

        $toRaw = mb_substr((string) $request->query('to', ''), 0, 10);
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1 ? $toRaw : null;

        return [
            'userId' => $userId === '' ? null : $userId,
            'entity' => $entity === '' ? null : $entity,
            'action' => $action === '' ? null : $action,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param  array{userId: ?string, entity: ?string, action: ?string, from: ?string, to: ?string}  $filters
     * @return Builder<AuditLog>
     */
    private function buildQuery(array $filters)
    {
        $query = AuditLog::query();

        if ($filters['userId'] !== null) {
            $query->where('user_id', $filters['userId']);
        }

        if ($filters['entity'] !== null) {
            $query->where('entity', $filters['entity']);
        }

        if ($filters['action'] !== null) {
            $query->where('action', $filters['action']);
        }

        $tz = Settings::timezone();

        if ($filters['from'] !== null) {
            $query->where('created_at', '>=', ClinicTime::combineDateAndTime($filters['from'], '00:00', $tz));
        }

        if ($filters['to'] !== null) {
            $query->where('created_at', '<', ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($filters['to'], 1), '00:00', $tz));
        }

        return $query;
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
