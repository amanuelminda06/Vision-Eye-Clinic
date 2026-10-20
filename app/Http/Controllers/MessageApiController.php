<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\TaskGenerator;
use App\Support\Patients\Csv;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The messaging API surface: the badge count, the outbox export and the cron
 * entry point for the message jobs.
 */
class MessageApiController extends Controller
{
    /** GET /api/messages/count — the sidebar badge. */
    public function count(): JsonResponse
    {
        try {
            $count = MsgQueries::countDueTasks();
        } catch (\Throwable) {
            $count = 0;
        }

        return response()->json(['ok' => true, 'count' => $count])
            ->header('Cache-Control', 'no-store');
    }

    /** GET /api/messages/export — the outbox CSV/XLSX. */
    public function export(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $format = (string) $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $rows = MsgQueries::listDueTasks(null, $tz);

        Audit::write('messageTask', 'export', 'EXPORTED', null, ['format' => $format], [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        $cells = array_map(fn (array $row): array => [
            $row['patientName'],
            $row['patientPhoneE164'] ?? '',
            $row['templateKey'],
            $row['language'],
            $row['status'],
            $row['dueAt']?->format('Y-m-d H:i') ?? '',
            $row['renderedMessage'],
        ], $rows);

        $headers = ['patient', 'phone', 'template', 'language', 'status', 'dueAt', 'message'];
        $filename = 'messages-'.gmdate('Y-m-d').'.csv';

        if ($format === 'xlsx') {
            $body = Xlsx::toXlsx([[
                'name' => 'messages',
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
     * POST /api/jobs/messages — the cron entry.
     *
     * Guarded by the `CRON_SECRET` bearer token (the Plesk cron calls it with
     * a header, never from the browser). The scheduled command
     * (`php artisan schedule:run` → `messages:run-jobs`) does the same work.
     */
    public function jobs(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron_secret', env('CRON_SECRET', ''));

        if ($secret === '') {
            return response()->json(['ok' => false, 'error' => 'unknownError'], 500);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $summary = TaskGenerator::runMessageJobs();

        return response()->json(['ok' => true, 'summary' => $summary])
            ->header('Cache-Control', 'no-store');
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
