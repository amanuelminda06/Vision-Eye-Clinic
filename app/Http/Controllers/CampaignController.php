<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignBatch;
use App\Models\MessageTask;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Messaging\Constants;
use App\Support\Messaging\GroupPlan;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\MsgRoles;
use App\Support\Messaging\RecipientFilter;
use App\Support\Messaging\Render;
use App\Support\Messaging\Templates;
use App\Support\Settings;
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
        ]);
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

        return array_merge(RecipientFilter::empty(), $raw);
    }
}
