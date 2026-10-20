<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Messaging\Constants;
use App\Support\Messaging\Eligibility;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\MsgRoles;
use App\Support\Messaging\Provider;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outbox (today's prepared-message queue) and the task actions.
 *
 * Rules preserved:
 * - MANUAL SEND ONLY: the app never transmits anything, it prepares an `sms:`
 *   link the operator opens on the clinic phone.
 * - A blocked task is never deleted — it is marked BLOCKED with a reason.
 * - Opening re-checks the live eligibility.
 */
class MessageController extends Controller
{
    /** GET /messages — the outbox. */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $platform = Provider::detectPlatform($request->userAgent());
        $groups = MsgQueries::listOutbox($now, $tz);
        $counts = MsgQueries::countDueToday($now, $tz);

        return response()->view('messages.index', [
            'groups' => $groups,
            'counts' => $counts,
            'platform' => $platform,
            'canEditTemplates' => MsgRoles::canEditTemplates($actor->role),
            'canManageLibrary' => MsgRoles::canManageLibrary($actor->role),
            'canSendCampaigns' => MsgRoles::canSendCampaigns(
                $actor->role,
                (bool) Settings::get('reception_can_send_campaigns', false),
            ),
            'timezone' => $tz,
        ]);
    }

    /** POST /messages/{id}/open — open a task (re-checks eligibility). */
    public function open(Request $request, string $id): RedirectResponse
    {
        $task = MessageTask::find($id);

        if ($task === null) {
            return back()->with('actionError', 'notFound');
        }

        if (in_array((string) $task->status, [MessageTask::STATUS_SENT, MessageTask::STATUS_SKIPPED, MessageTask::STATUS_CANCELLED], true)) {
            return back()->with('actionError', 'taskAlreadyHandled');
        }

        $reason = $this->liveBlockedReason($task);

        if ($reason !== null) {
            $task->forceFill([
                'status' => MessageTask::STATUS_BLOCKED,
                'blocked_reason' => $reason,
            ])->save();
        } else {
            $task->forceFill([
                'status' => MessageTask::STATUS_OPENED,
                'blocked_reason' => null,
            ])->save();
        }

        return back();
    }

    /** POST /messages/{id}/sent — the operator confirmed the SMS went out. */
    public function sent(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $task = MessageTask::find($id);

        if ($task === null) {
            return back()->with('actionError', 'notFound');
        }

        // Already sent → no second audit.
        if ((string) $task->status === MessageTask::STATUS_SENT) {
            return back();
        }

        $task->forceFill([
            'status' => MessageTask::STATUS_SENT,
            'handled_by' => $actor->id,
            'handled_at' => ClinicTime::nowUtc(),
            'blocked_reason' => null,
        ])->save();

        Audit::write('MessageTask', $id, 'MESSAGE_SENT', null, null, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back();
    }

    /** POST /messages/{id}/postpone — "not yet". */
    public function postpone(Request $request, string $id): RedirectResponse
    {
        $task = MessageTask::find($id);

        if ($task === null) {
            return back()->with('actionError', 'notFound');
        }

        if (in_array((string) $task->status, [MessageTask::STATUS_SENT, MessageTask::STATUS_CANCELLED], true)) {
            return back()->with('actionError', 'taskAlreadyHandled');
        }

        // A stored blocked reason is NOT cleared by a postpone.
        $task->forceFill(['status' => MessageTask::STATUS_PENDING])->save();

        return back();
    }

    /** POST /messages/{id}/skip — skip with a reason. */
    public function skip(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $task = MessageTask::find($id);

        if ($task === null) {
            return back()->with('actionError', 'notFound');
        }

        if ((string) $task->status === MessageTask::STATUS_SENT) {
            return back()->with('actionError', 'taskAlreadyHandled');
        }

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '' || mb_strlen($reason) > Constants::MAX_SKIP_REASON) {
            return back()->withErrors(['reason' => $reason === '' ? 'required' : 'tooLong'])->withInput();
        }

        $task->forceFill([
            'status' => MessageTask::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'handled_by' => $actor->id,
            'handled_at' => ClinicTime::nowUtc(),
        ])->save();

        Audit::write('MessageTask', $id, 'MESSAGE_SKIPPED', null, ['reason' => $reason], [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back();
    }

    /** POST /messages/{id}/edit — a one-off body edit. */
    public function edit(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $task = MessageTask::find($id);

        if ($task === null) {
            return back()->with('actionError', 'notFound');
        }

        if (in_array((string) $task->status, [MessageTask::STATUS_SENT, MessageTask::STATUS_CANCELLED], true)) {
            return back()->with('actionError', 'taskAlreadyHandled');
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '' || mb_strlen($message) > Constants::MAX_CAMPAIGN_MESSAGE) {
            return back()->withErrors(['message' => $message === '' ? 'required' : 'tooLong'])->withInput();
        }

        $previous = (string) $task->rendered_message;

        $task->forceFill([
            'rendered_message' => $message,
            'edited_by' => $actor->id,
            'edited_at' => ClinicTime::nowUtc(),
        ])->save();

        Audit::write('MessageTask', $id, 'MESSAGE_EDITED',
            ['message' => $previous],
            ['message' => $message],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }

    /**
     * The live blocked reason of a task (null when it may be sent).
     */
    private function liveBlockedReason(MessageTask $task): ?string
    {
        $patient = Patient::find($task->patient_id);

        if ($patient === null) {
            return 'inactive';
        }

        return Eligibility::blockedReason([
            'patient' => [
                'active' => (bool) $patient->active,
                'doNotMessage' => (bool) $patient->do_not_message,
                'phoneE164' => (string) $patient->phone_e164,
            ],
            'sourceStatus' => $this->sourceStatus($task),
        ]);
    }

    /**
     * The status of the appointment / follow-up that produced the task.
     */
    private function sourceStatus(MessageTask $task): ?string
    {
        $sourceType = (string) $task->source_type;
        $sourceId = (string) $task->source_id;

        if ($sourceType === 'APPOINTMENT') {
            // Reminder/same-day source ids carry a `#<timestamp>` version suffix.
            $appointmentId = explode('#', $sourceId)[0];
            $appointment = Appointment::find($appointmentId);

            return $appointment === null ? null : (string) $appointment->status;
        }

        if ($sourceType === 'FOLLOWUP') {
            $followUp = FollowUp::find($sourceId);

            return $followUp === null ? null : (string) $followUp->status;
        }

        return null;
    }
}
