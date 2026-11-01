<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignBatch;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Messaging\Constants;
use App\Support\Messaging\GroupPlan;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\MsgRoles;
use App\Support\Messaging\RecipientFilter;
use App\Support\Messaging\Render;
use App\Support\Messaging\Templates;
use App\Support\Patients\SystemId;
use App\Support\Phone;
use App\Support\Services\ServiceQueries;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Campaigns: the list, the audience preview, creation (INDIVIDUAL or GROUP)
 * and the batch handling.
 */
class CampaignController extends Controller
{
    /** GET /messages/campaigns */
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return response()->view('messages.campaigns', [
            'rows' => MsgQueries::listCampaigns(),
            'canSend' => MsgRoles::canSendCampaigns(
                $actor->role,
                (bool) Settings::get('reception_can_send_campaigns', false),
            ),
        ]);
    }

    /** GET /messages/campaigns/new — the audience preview + creation form. */
    public function create(Request $request): Response
    {
        $actor = $request->user();

        $canSend = MsgRoles::canSendCampaigns(
            $actor->role,
            (bool) Settings::get('reception_can_send_campaigns', false),
        );

        if (! $canSend) {
            return response()->view('messages.campaigns-forbidden', [], 403);
        }

        $filter = RecipientFilter::normalize($this->readFilter($request));
        $candidates = MsgQueries::listCandidates();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), Settings::timezone());

        $resolved = $filter;
        $preset = RecipientFilter::resolvePresetRange($filter, $todayKey);
        $resolved['lastVisitFrom'] = $preset['lastVisitFrom'];
        $resolved['lastVisitTo'] = $preset['lastVisitTo'];

        $cap = (int) Settings::get('campaign_max_recipients', 300);
        $allowFiltered = (bool) Settings::get('group_sms_allow_for_filtered', false);

        $summary = RecipientFilter::summarize($candidates, $resolved, $cap, $allowFiltered);

        return response()->view('messages.campaign-new', [
            'filter' => $resolved,
            'preview' => $summary['preview'],
            'eligibleCount' => count($summary['eligible']),
            'canSend' => $canSend,
            'bodies' => MsgQueries::loadTemplateBodies(),
            'cap' => $cap,
            'templateKeys' => Constants::TEMPLATE_KEYS,
            'services' => ServiceQueries::listServiceOptions(),
        ]);
    }

    /**
     * GET /messages/recipients — the composer's patient type-ahead.
     *
     * The global /api/search route returns six hits sorted alphabetically,
     * which in a database of ~19k patients made everyone whose name sorts
     * after the "A…" block effectively impossible to pick. This endpoint
     * returns a relevance-ordered window (prefix hits first) and also matches
     * the system ID (AA0001) and the clinic ID shown on the patients list.
     */
    public function recipients(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! MsgRoles::canSendCampaigns(
            $actor->role,
            (bool) Settings::get('reception_can_send_campaigns', false),
        )) {
            abort(403);
        }

        $columns = ['id', 'full_name', 'phone_e164', 'system_id', 'id_no'];

        // Re-load the names of already-picked patients (after a failed submit).
        $idsParam = trim((string) $request->query('ids', ''));

        if ($idsParam !== '') {
            $ids = array_slice(array_values(array_filter(array_map('trim', explode(',', $idsParam)))), 0, 100);

            if ($ids === []) {
                return response()->json(['patients' => []])->header('Cache-Control', 'no-store');
            }

            $byId = Patient::query()->whereIn('id', $ids)->get($columns)->keyBy('id');
            $ordered = [];

            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $ordered[] = self::recipientRow($byId[$id]);
                }
            }

            return response()->json(['patients' => $ordered])->header('Cache-Control', 'no-store');
        }

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json(['patients' => []])->header('Cache-Control', 'no-store');
        }

        $contains = '%'.self::escapeLike($q).'%';
        $starts = self::escapeLike($q).'%';

        $patients = Patient::query()
            ->where('active', true)
            ->where(function ($inner) use ($contains, $q): void {
                $inner->where('full_name', 'like', $contains)
                    ->orWhere('system_id', 'like', $contains)
                    ->orWhere('id_no', 'like', $contains);

                foreach (Phone::phoneSearchVariants($q) ?? [] as $variant) {
                    $inner->orWhere('phone_e164', 'like', '%'.self::escapeLike($variant).'%');
                }
            })
            ->orderByRaw(
                'CASE
                    WHEN full_name LIKE ? THEN 0
                    WHEN system_id LIKE ? THEN 1
                    WHEN id_no LIKE ? THEN 2
                    WHEN full_name LIKE ? THEN 3
                    ELSE 4
                END',
                [$starts, $starts, $starts, $contains],
            )
            ->orderBy('full_name')
            ->limit(30)
            ->get($columns);

        return response()->json([
            'patients' => $patients->map(fn (Patient $patient): array => self::recipientRow($patient))->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /** One picker hit (the shape the composer JS expects). */
    private static function recipientRow(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'title' => (string) $patient->full_name,
            'subtitle' => implode(' · ', array_filter([
                (string) $patient->system_id,
                (string) $patient->phone_e164,
            ], static fn (string $part): bool => $part !== '')),
            'systemId' => $patient->system_id,
            'phoneE164' => $patient->phone_e164,
        ];
    }

    /** Escape LIKE wildcards so user fragments match literally. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** GET /messages/campaigns/{id} — the campaign detail. */
    public function show(Request $request, string $id): Response
    {
        $campaign = MsgQueries::getCampaign($id);

        if ($campaign === null) {
            abort(404);
        }

        return response()->view('messages.campaign-show', [
            'campaign' => $campaign,
            'batches' => MsgQueries::listCampaignBatches($id),
            'tasks' => MsgQueries::listCampaignTasks($id),
        ]);
    }

    /** POST /messages/campaigns — create a campaign (and its tasks). */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $canSend = MsgRoles::canSendCampaigns(
            $actor->role,
            (bool) Settings::get('reception_can_send_campaigns', false),
        );

        if (! $canSend) {
            abort(403);
        }

        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        $languageMode = (string) $request->input('languageMode', 'PATIENT');
        $fixedLanguage = (string) $request->input('fixedLanguage', 'en');
        $deliveryMode = (string) $request->input('deliveryMode', 'INDIVIDUAL');
        $batchSizeRaw = $request->input('groupBatchSize', 15);
        $acknowledged = (string) $request->input('acknowledgedGroupWarning', 'false') === 'true';

        if ($title === '' || mb_strlen($title) > Constants::MAX_CAMPAIGN_TITLE) {
            return back()->withErrors(['title' => $title === '' ? 'required' : 'tooLong'])->withInput();
        }

        $check = Templates::validateMessageBody($body);
        if (! $check['ok']) {
            return back()->withErrors(['body' => $check['error']])->withInput();
        }

        if (! in_array($languageMode, Constants::CAMPAIGN_LANGUAGE_MODES, true)) {
            return back()->withErrors(['languageMode' => 'invalid'])->withInput();
        }

        if (! in_array($deliveryMode, Constants::CAMPAIGN_DELIVERY_MODES, true)) {
            return back()->withErrors(['deliveryMode' => 'invalid'])->withInput();
        }

        $filter = RecipientFilter::normalize($this->readFilter($request));
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), Settings::timezone());
        $resolved = $filter;
        $preset = RecipientFilter::resolvePresetRange($filter, $todayKey);
        $resolved['lastVisitFrom'] = $preset['lastVisitFrom'];
        $resolved['lastVisitTo'] = $preset['lastVisitTo'];

        $candidates = MsgQueries::listCandidates();
        $cap = (int) Settings::get('campaign_max_recipients', 300);
        $allowFiltered = (bool) Settings::get('group_sms_allow_for_filtered', false);

        $summary = RecipientFilter::summarize($candidates, $resolved, $cap, $allowFiltered);

        if ($summary['preview']['overCap']) {
            return back()->with('actionError', 'campaignOverCap')->withInput();
        }

        if ($deliveryMode === 'GROUP') {
            $reasons = RecipientFilter::groupBlockReasons($resolved);

            if ($reasons !== [] && ! ($allowFiltered && MsgRoles::canOverrideGroupFilter($actor->role))) {
                return back()->with('actionError', 'groupBlocked')->withInput();
            }

            if ($reasons !== [] && ! $acknowledged) {
                return back()->with('actionError', 'groupAcknowledgeRequired')->withInput();
            }
        }

        $eligible = $summary['eligible'];
        if ($eligible === []) {
            return back()->with('actionError', 'noRecipients')->withInput();
        }
        $bodies = MsgQueries::loadTemplateBodies();
        $clinicName = (string) Settings::get('clinic_name', '');
        $reviewLink = (string) Settings::get('review_link', '');

        $campaign = DB::transaction(function () use (
            $actor, $title, $body, $languageMode, $fixedLanguage, $deliveryMode,
            $resolved, $eligible, $clinicName, $reviewLink, $batchSizeRaw
        ): Campaign {
            $campaign = Campaign::create([
                'title' => $title,
                'template_key' => 'CUSTOM',
                'custom_body' => $body,
                'language_mode' => $languageMode,
                'fixed_language' => $languageMode === 'FIXED' ? $fixedLanguage : null,
                'filter_json' => json_encode($resolved),
                'status' => Campaign::STATUS_ACTIVE,
                'delivery_mode' => $deliveryMode,
                'created_by' => $actor->id,
            ]);

            $batchSize = GroupPlan::resolveGroupBatchSize($batchSizeRaw);
            $batches = [];

            if ($deliveryMode === 'GROUP') {
                $sizes = GroupPlan::planBatches(count($eligible), $batchSize);
                $offset = 0;

                foreach ($sizes as $index => $size) {
                    $batch = CampaignBatch::create([
                        'campaign_id' => $campaign->id,
                        'batch_number' => $index + 1,
                        'size' => $size,
                        'status' => CampaignBatch::STATUS_PENDING,
                    ]);

                    $batches[$index] = ['batch' => $batch, 'offset' => $offset];
                    $offset += $size;
                }
            }

            foreach (array_values($eligible) as $index => $candidate) {
                $language = $languageMode === 'FIXED'
                    ? $fixedLanguage
                    : Render::resolveLanguage($candidate['preferredLanguage']);

                $rendered = Render::renderMessage($body, [
                    'patientName' => $candidate['fullName'],
                    'language' => $language,
                    'clinicName' => $clinicName,
                    'reviewLink' => $reviewLink,
                    'timeZone' => Settings::timezone(),
                ]);

                $batchId = null;
                if ($deliveryMode === 'GROUP' && $batches !== []) {
                    foreach ($batches as $entry) {
                        if ($index >= $entry['offset'] && $index < $entry['offset'] + $entry['batch']->size) {
                            $batchId = $entry['batch']->id;
                            break;
                        }
                    }
                }

                MessageTask::create([
                    'patient_id' => $candidate['id'],
                    'template_key' => 'CUSTOM',
                    'source_type' => 'CAMPAIGN',
                    'source_id' => $campaign->id,
                    'campaign_id' => $campaign->id,
                    'campaign_batch_id' => $batchId,
                    'language' => $language,
                    'rendered_message' => $rendered,
                    'due_at' => ClinicTime::nowUtc(),
                    'status' => MessageTask::STATUS_PENDING,
                    'created_by' => $actor->id,
                ]);
            }

            return $campaign;
        });

        Audit::write('Campaign', $campaign->id, 'CAMPAIGN_CREATED', null, [
            'title' => $title,
            'deliveryMode' => $deliveryMode,
            'recipients' => count($eligible),
        ], ['id' => $actor->id, 'name' => $actor->name]);

        if ($deliveryMode === 'INDIVIDUAL') {
            return redirect('/messages');
        }

        return redirect('/messages/campaigns/'.$campaign->id);
    }

    /** POST /messages/campaigns/{id}/batches/{batchId} — sent / skipped / cancel. */
    public function batch(Request $request, string $id, string $batchId): RedirectResponse
    {
        $actor = $request->user();

        $batch = CampaignBatch::query()
            ->where('id', $batchId)
            ->where('campaign_id', $id)
            ->first();

        if ($batch === null) {
            return back()->with('actionError', 'notFound');
        }

        $action = (string) $request->input('action', '');

        if ($action === 'sent') {
            $batch->forceFill([
                'status' => CampaignBatch::STATUS_SENT,
                'handled_by' => $actor->id,
                'handled_at' => ClinicTime::nowUtc(),
            ])->save();

            MessageTask::query()
                ->where('campaign_batch_id', $batch->id)
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->update([
                    'status' => MessageTask::STATUS_SENT,
                    'handled_by' => $actor->id,
                    'handled_at' => ClinicTime::nowUtc(),
                ]);

            Audit::write('CampaignBatch', $batch->id, 'CAMPAIGN_BATCH_SENT', null, null, [
                'id' => $actor->id,
                'name' => $actor->name,
            ]);

            return back();
        }

        if ($action === 'skip') {
            $reason = trim((string) $request->input('reason', ''));

            if ($reason === '' || mb_strlen($reason) > Constants::MAX_SKIP_REASON) {
                return back()->withErrors(['reason' => $reason === '' ? 'required' : 'tooLong'])->withInput();
            }

            $batch->forceFill([
                'status' => CampaignBatch::STATUS_SKIPPED,
                'handled_by' => $actor->id,
                'handled_at' => ClinicTime::nowUtc(),
                'skip_reason' => $reason,
            ])->save();

            MessageTask::query()
                ->where('campaign_batch_id', $batch->id)
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->update([
                    'status' => MessageTask::STATUS_SKIPPED,
                    'skip_reason' => $reason,
                    'handled_by' => $actor->id,
                    'handled_at' => ClinicTime::nowUtc(),
                ]);

            Audit::write('CampaignBatch', $batch->id, 'CAMPAIGN_BATCH_SKIPPED', null, ['reason' => $reason], [
                'id' => $actor->id,
                'name' => $actor->name,
            ]);

            return back();
        }

        if ($action === 'cancel') {
            $batch->forceFill([
                'status' => CampaignBatch::STATUS_SKIPPED,
                'handled_by' => $actor->id,
                'handled_at' => ClinicTime::nowUtc(),
                'skip_reason' => 'Cancelled',
            ])->save();

            MessageTask::query()
                ->where('campaign_id', $id)
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->update([
                    'status' => MessageTask::STATUS_CANCELLED,
                    'handled_by' => $actor->id,
                    'handled_at' => ClinicTime::nowUtc(),
                ]);

            return back();
        }

        return back()->with('actionError', 'unknownError');
    }

    /**
     * Reads the recipient filter from the request (form or JSON body).
     *
     * @return array<string, mixed>
     */
    private function readFilter(Request $request): array
    {
        $raw = $request->input('filter');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        // Support comma-separated text inputs coming from the filter form.
        foreach (['tags', 'serviceIds', 'handPickedIds'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key])) {
                $raw[$key] = array_values(array_filter(array_map('trim', explode(',', $raw[$key]))));
            }
        }

        // Top-level hand-picked IDs (from the patient picker) override/merge.
        $topPicked = $request->input('handPickedIds');
        if (is_string($topPicked) && $topPicked !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $topPicked))));
            if ($ids !== []) {
                $raw['handPickedIds'] = array_values(array_unique(array_merge($raw['handPickedIds'] ?? [], $ids)));
            }
        } elseif (is_array($topPicked)) {
            $ids = array_values(array_filter($topPicked, 'is_string'));
            if ($ids !== []) {
                $raw['handPickedIds'] = array_values(array_unique(array_merge($raw['handPickedIds'] ?? [], $ids)));
            }
        }

        // Accept the human-facing system ID (AA0001) as well as the internal
        // patient id, so pasting what the patients list shows still works.
        $raw['handPickedIds'] = $this->resolveHandPickedIds(
            array_values(array_filter((array) ($raw['handPickedIds'] ?? []), 'is_string')),
        );

        return array_merge(RecipientFilter::empty(), $raw);
    }

    /**
     * Normalises the hand-picked tokens: internal ULIDs stay as-is, system
     * IDs (AA0001) are resolved to their patient id, and system IDs that do
     * not exist are dropped.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function resolveHandPickedIds(array $tokens): array
    {
        $ids = [];
        $systemIds = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (SystemId::isValid(strtoupper($token))) {
                $systemIds[] = strtoupper($token);
            } else {
                $ids[] = $token;
            }
        }

        if ($systemIds !== []) {
            $ids = array_merge(
                $ids,
                Patient::query()->whereIn('system_id', array_values(array_unique($systemIds)))->pluck('id')->all(),
            );
        }

        return array_values(array_unique($ids));
    }
}
