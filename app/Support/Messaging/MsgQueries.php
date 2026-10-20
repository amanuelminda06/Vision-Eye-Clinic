<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\Appointment;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignBatch;
use App\Models\MessageDraft;
use App\Models\MessageLibraryItem;
use App\Models\MessageTask;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Messaging queries, ported from src/modules/messaging/queries.ts and
 * recipients.ts.
 */
final class MsgQueries
{
    /**
     * The active template bodies as a `KEY:lang` map.
     *
     * @return array<string, string>
     */
    public static function loadTemplateBodies(): array
    {
        $bodies = [];

        foreach (MessageTemplate::query()->where('active', true)->get(['key', 'language', 'body']) as $row) {
            $bodies[$row->key.':'.$row->language] = (string) $row->body;
        }

        return $bodies;
    }

    /**
     * The outbox groups (the legacy `listOutbox`).
     *
     * - `dueNow`     — open and due at or before now.
     * - `laterToday` — open and due later today.
     * - `overdue`    — open but due before the clinic day started.
     * - `doneToday`  — sent or skipped today.
     * - `blocked`    — the patient could not be messaged.
     *
     * @return array{dueNow: list<array<string, mixed>>, laterToday: list<array<string, mixed>>, overdue: list<array<string, mixed>>, doneToday: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public static function listOutbox(?\DateTimeInterface $now = null, ?string $timeZone = null): array
    {
        $now ??= ClinicTime::nowUtc();
        $timeZone ??= Settings::timezone();

        $todayKey = ClinicTime::clinicDateKey($now, $timeZone);
        $dayStart = ClinicTime::combineDateAndTime($todayKey, '00:00', $timeZone);
        $dayEnd = ClinicTime::endOfDay($now, $timeZone);

        $rows = MessageTask::query()
            ->with(['patient:id,full_name,phone_e164', 'campaign:id,title'])
            ->where(function (Builder $inner) use ($dayEnd, $dayStart): void {
                $inner->where(function (Builder $open) use ($dayEnd): void {
                    $open->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                        ->where('due_at', '<', $dayEnd);
                })->orWhere('status', MessageTask::STATUS_BLOCKED)
                    ->orWhere(function (Builder $done) use ($dayStart, $dayEnd): void {
                        $done->whereIn('status', [MessageTask::STATUS_SENT, MessageTask::STATUS_SKIPPED])
                            ->where('handled_at', '>=', $dayStart)
                            ->where('handled_at', '<', $dayEnd);
                    });
            })
            ->orderBy('due_at')
            ->take(500)
            ->get();

        $groups = [
            'dueNow' => [],
            'laterToday' => [],
            'overdue' => [],
            'doneToday' => [],
            'blocked' => [],
        ];

        foreach ($rows as $row) {
            $serialized = self::serializeTask($row);

            if ((string) $row->status === MessageTask::STATUS_BLOCKED) {
                $groups['blocked'][] = $serialized;

                continue;
            }

            if (in_array((string) $row->status, [MessageTask::STATUS_SENT, MessageTask::STATUS_SKIPPED], true)) {
                $groups['doneToday'][] = $serialized;

                continue;
            }

            $dueAt = $row->due_at?->getTimestamp() ?? 0;

            if ($dueAt < $dayStart->getTimestamp()) {
                $groups['overdue'][] = $serialized;
            } elseif ($dueAt <= $now->getTimestamp()) {
                $groups['dueNow'][] = $serialized;
            } else {
                $groups['laterToday'][] = $serialized;
            }
        }

        return $groups;
    }

    /**
     * PENDING/OPENED tasks that are due now or overdue — the sidebar badge.
     */
    public static function countDueTasks(?\DateTimeInterface $now = null): int
    {
        $now ??= ClinicTime::nowUtc();

        return MessageTask::query()
            ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
            ->where('due_at', '<=', $now)
            ->count();
    }

    /**
     * The dashboard banner numbers: due today and overdue.
     *
     * @return array{dueToday: int, overdue: int}
     */
    public static function countDueToday(?\DateTimeInterface $now = null, ?string $timeZone = null): array
    {
        $now ??= ClinicTime::nowUtc();
        $timeZone ??= Settings::timezone();

        $todayKey = ClinicTime::clinicDateKey($now, $timeZone);
        $dayStart = ClinicTime::combineDateAndTime($todayKey, '00:00', $timeZone);
        $dayEnd = ClinicTime::endOfDay($now, $timeZone);

        return [
            'dueToday' => MessageTask::query()
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->where('due_at', '>=', $dayStart)
                ->where('due_at', '<', $dayEnd)
                ->count(),
            'overdue' => MessageTask::query()
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->where('due_at', '<', $dayStart)
                ->count(),
        ];
    }

    /**
     * The outbox: tasks due today (or still open), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function listDueTasks(?string $todayKey = null, ?string $timeZone = null): array
    {
        $timeZone ??= Settings::timezone();
        $todayKey ??= ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $timeZone);

        $from = ClinicTime::combineDateAndTime($todayKey, '00:00', $timeZone);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($todayKey, 1), '00:00', $timeZone);

        $rows = MessageTask::query()
            ->with(['patient:id,full_name,phone_e164', 'campaign:id,title'])
            ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED, MessageTask::STATUS_BLOCKED])
            ->where('due_at', '<', $to)
            ->orderBy('due_at')
            ->take(300)
            ->get();

        return $rows->map(fn (MessageTask $task): array => self::serializeTask($task))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getTask(string $id): ?array
    {
        $task = MessageTask::query()
            ->with(['patient:id,full_name,phone_e164', 'campaign:id,title'])
            ->find($id);

        return $task === null ? null : self::serializeTask($task);
    }

    /**
     * The message history of one patient (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public static function listPatientMessages(string $patientId, int $limit = 100): array
    {
        return MessageTask::query()
            ->with('patient:id,full_name,phone_e164')
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->map(fn (MessageTask $task): array => self::serializeTask($task))->all();
    }

    public static function countPatientMessages(string $patientId): int
    {
        return MessageTask::query()->where('patient_id', $patientId)->count();
    }

    /**
     * The template rows (all key+language combinations that exist).
     *
     * @return list<array<string, mixed>>
     */
    public static function listTemplates(): array
    {
        return MessageTemplate::query()
            ->orderBy('key')
            ->orderBy('language')
            ->get()
            ->map(fn (MessageTemplate $row): array => [
                'id' => $row->id,
                'key' => (string) $row->key,
                'language' => (string) $row->language,
                'body' => (string) $row->body,
                'active' => (bool) $row->active,
            ])->all();
    }

    /**
     * The library items (favourites first, then by usage).
     *
     * @return list<array<string, mixed>>
     */
    public static function listLibraryItems(bool $includeArchived = false): array
    {
        $query = MessageLibraryItem::query()
            ->orderByDesc('is_favorite')
            ->orderByDesc('use_count')
            ->orderByDesc('last_used_at')
            ->orderBy('title');

        if (! $includeArchived) {
            $query->where('active', true);
        }

        return $query->get()->map(fn (MessageLibraryItem $item): array => [
            'id' => $item->id,
            'title' => (string) $item->title,
            'body' => (string) $item->body,
            'language' => (string) $item->language,
            'category' => (string) $item->category,
            'isFavorite' => (bool) $item->is_favorite,
            'useCount' => (int) $item->use_count,
            'lastUsedAt' => $item->last_used_at,
            'active' => (bool) $item->active,
            'pairId' => $item->pair_id,
        ])->all();
    }

    /**
     * The drafts of one user (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public static function listDrafts(string $userId): array
    {
        return MessageDraft::query()
            ->where('created_by', $userId)
            ->orderByDesc('updated_at')
            ->take(200)
            ->get()
            ->map(fn (MessageDraft $draft): array => [
                'id' => $draft->id,
                'title' => $draft->title,
                'body' => (string) $draft->body,
                'language' => (string) $draft->language,
                'updatedAt' => $draft->updated_at,
            ])->all();
    }

    /**
     * The campaigns (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public static function listCampaigns(): array
    {
        return Campaign::query()
            ->withCount(['tasks', 'batches'])
            ->orderByDesc('created_at')
            ->take(200)
            ->get()
            ->map(fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'title' => (string) $campaign->title,
                'status' => (string) $campaign->status,
                'deliveryMode' => (string) $campaign->delivery_mode,
                'languageMode' => (string) $campaign->language_mode,
                'taskCount' => (int) $campaign->tasks_count,
                'batchCount' => (int) $campaign->batches_count,
                'createdAt' => $campaign->created_at,
            ])->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCampaign(string $id): ?array
    {
        $campaign = Campaign::query()->withCount(['tasks', 'batches'])->find($id);

        if ($campaign === null) {
            return null;
        }

        return [
            'id' => $campaign->id,
            'title' => (string) $campaign->title,
            'status' => (string) $campaign->status,
            'deliveryMode' => (string) $campaign->delivery_mode,
            'languageMode' => (string) $campaign->language_mode,
            'fixedLanguage' => $campaign->fixed_language,
            'templateKey' => $campaign->template_key,
            'customBody' => $campaign->custom_body,
            'libraryItemId' => $campaign->library_item_id,
            'filter' => RecipientFilter::normalize(json_decode((string) $campaign->filter_json, true)),
            'taskCount' => (int) $campaign->tasks_count,
            'batchCount' => (int) $campaign->batches_count,
            'createdAt' => $campaign->created_at,
        ];
    }

    /**
     * The batches of a campaign.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCampaignBatches(string $campaignId): array
    {
        return CampaignBatch::query()
            ->where('campaign_id', $campaignId)
            ->orderBy('batch_number')
            ->get()
            ->map(fn (CampaignBatch $batch): array => [
                'id' => $batch->id,
                'batchNumber' => (int) $batch->batch_number,
                'size' => (int) $batch->size,
                'status' => (string) $batch->status,
                'handledAt' => $batch->handled_at,
                'skipReason' => $batch->skip_reason,
            ])->all();
    }

    /**
     * The tasks of a campaign.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCampaignTasks(string $campaignId): array
    {
        return MessageTask::query()
            ->with('patient:id,full_name,phone_e164')
            ->where('campaign_id', $campaignId)
            ->orderBy('created_at')
            ->take(500)
            ->get()
            ->map(fn (MessageTask $task): array => self::serializeTask($task))->all();
    }

    /**
     * The saved audiences (owner-managed).
     *
     * @return list<array<string, mixed>>
     */
    public static function listAudiences(): array
    {
        return Audience::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Audience $audience): array => [
                'id' => $audience->id,
                'name' => (string) $audience->name,
                'filter' => RecipientFilter::normalize(json_decode((string) $audience->filter_json, true)),
            ])->all();
    }

    /**
     * The expired-but-not-sent report, grouped by day.
     *
     * @return list<array{dateKey: string, count: int, tasks: list<array<string, mixed>>}>
     */
    public static function expiredNotSentReport(string $timeZone): array
    {
        $rows = MessageTask::query()
            ->with('patient:id,full_name,phone_e164')
            ->where('status', MessageTask::STATUS_EXPIRED)
            ->orderByDesc('expires_at')
            ->take(1000)
            ->get();

        $byDay = [];

        foreach ($rows as $task) {
            $dayKey = ClinicTime::clinicDateKey($task->expires_at ?? $task->created_at, $timeZone);
            $byDay[$dayKey][] = self::serializeTask($task);
        }

        $report = [];

        foreach ($byDay as $dayKey => $tasks) {
            $report[] = ['dateKey' => $dayKey, 'count' => count($tasks), 'tasks' => $tasks];
        }

        return $report;
    }

    /**
     * The campaign candidates (patients) with the derived attributes the
     * filters need.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCandidates(int $limit = 1000): array
    {
        $patients = Patient::query()
            ->with('tags:id,name')
            ->orderBy('full_name')
            ->take($limit)
            ->get();

        if ($patients->isEmpty()) {
            return [];
        }

        $ids = $patients->pluck('id')->all();

        $lastVisits = self::lastActivityBy($ids, 'visit');
        $lastPurchases = self::lastActivityBy($ids, 'purchase');

        $upcoming = Appointment::query()
            ->whereIn('patient_id', $ids)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', ClinicTime::nowUtc())
            ->pluck('patient_id')
            ->unique()
            ->all();

        $servicesByPatient = self::servicesByPatient($ids);

        return $patients->map(function (Patient $patient) use ($lastVisits, $lastPurchases, $upcoming, $servicesByPatient): array {
            $services = $servicesByPatient[$patient->id] ?? ['serviceIds' => [], 'categories' => []];

            return [
                'id' => $patient->id,
                'fullName' => (string) $patient->full_name,
                'phoneE164' => (string) $patient->phone_e164,
                'preferredLanguage' => (string) $patient->preferred_language,
                'doNotMessage' => (bool) $patient->do_not_message,
                'active' => (bool) $patient->active,
                'birthDate' => $patient->birth_date,
                'tags' => $patient->tags->pluck('name')->all(),
                'lastVisitAt' => $lastVisits[$patient->id] ?? null,
                'lastPurchaseAt' => $lastPurchases[$patient->id] ?? null,
                'hasUpcoming' => in_array($patient->id, $upcoming, true),
                'categories' => $services['categories'],
                'serviceIds' => $services['serviceIds'],
            ];
        })->all();
    }

    /**
     * The active doctors for the recipient picker.
     *
     * @return Collection<int, Staff>
     */
    public static function listDoctorOptions(): Collection
    {
        return Staff::query()
            ->where('active', true)
            ->where('is_doctor', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);
    }

    /**
     * The last visit / purchase instant per patient.
     *
     * @param  list<string>  $patientIds
     * @return array<string, \DateTimeInterface>
     */
    private static function lastActivityBy(array $patientIds, string $kind): array
    {
        $result = [];

        if ($kind === 'visit') {
            $rows = Appointment::query()
                ->whereIn('patient_id', $patientIds)
                ->where('status', Appointment::STATUS_COMPLETED)
                ->orderBy('starts_at')
                ->get(['patient_id', 'starts_at']);

            foreach ($rows as $row) {
                $result[$row->patient_id] = $row->starts_at;
            }

            return $result;
        }

        $rows = Sale::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', Sale::STATUS_CONFIRMED)
            ->orderBy('sale_date')
            ->get(['patient_id', 'sale_date']);

        foreach ($rows as $row) {
            $result[$row->patient_id] = $row->sale_date;
        }

        return $result;
    }

    /**
     * The purchased service ids and categories per patient.
     *
     * @param  list<string>  $patientIds
     * @return array<string, array{serviceIds: list<string>, categories: list<string>}>
     */
    private static function servicesByPatient(array $patientIds): array
    {
        $rows = SaleLine::query()
            ->with(['service:id,category', 'sale:id,patient_id'])
            ->whereIn('sale_id', Sale::query()
                ->whereIn('patient_id', $patientIds)
                ->where('status', Sale::STATUS_CONFIRMED)
                ->select('id'))
            ->get();

        $result = [];

        foreach ($rows as $line) {
            $patientId = (string) $line->sale?->patient_id;

            if ($patientId === '') {
                continue;
            }

            $result[$patientId]['serviceIds'][] = (string) $line->service_id;

            $category = $line->service?->category;
            if ($category !== null && $category !== '') {
                $result[$patientId]['categories'][] = $category;
            }
        }

        foreach ($result as $patientId => $data) {
            $result[$patientId] = [
                'serviceIds' => array_values(array_unique($data['serviceIds'])),
                'categories' => array_values(array_unique($data['categories'])),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeTask(MessageTask $task): array
    {
        return [
            'id' => $task->id,
            'patientId' => $task->patient_id,
            'patientName' => $task->patient?->full_name ?? '',
            'patientPhoneE164' => $task->patient?->phone_e164,
            'templateKey' => (string) $task->template_key,
            'sourceType' => (string) $task->source_type,
            'sourceId' => (string) $task->source_id,
            'campaignId' => $task->campaign_id,
            'campaignTitle' => $task->campaign?->title,
            'language' => (string) $task->language,
            'renderedMessage' => (string) $task->rendered_message,
            'dueAt' => $task->due_at,
            'sendBy' => $task->send_by,
            'expiresAt' => $task->expires_at,
            'status' => (string) $task->status,
            'blockedReason' => $task->blocked_reason,
            'skipReason' => $task->skip_reason,
            'handledBy' => $task->handled_by,
            'handledAt' => $task->handled_at,
            'editedAt' => $task->edited_at,
            'createdAt' => $task->created_at,
        ];
    }
}
