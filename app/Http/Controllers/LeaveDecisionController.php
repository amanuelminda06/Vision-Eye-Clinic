<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Hr\HrRoles;
use App\Support\Hr\HrValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave approvals (OWNER only): the inbox plus the approve/reject decisions.
 *
 * Segregation of duties: nobody decides their own leave. The check compares
 * the actor's linked staff id with the request's staff id — a user account
 * without a staff link is never "self", so a peer decides.
 */
class LeaveDecisionController extends Controller
{
    /** GET /leave/inbox — the pending requests. */
    public function index(): Response
    {
        return response()->view('hr.leave-inbox', [
            'rows' => HrQueries::listPendingLeaveRequests(),
        ]);
    }

    /** POST /leave/{id}/approve — approve a pending request. */
    public function approve(Request $request, string $id): RedirectResponse
    {
        return $this->decide($request, $id, LeaveRequest::STATUS_APPROVED, 'LEAVE_APPROVED');
    }

    /** POST /leave/{id}/reject — reject a pending request. */
    public function reject(Request $request, string $id): RedirectResponse
    {
        return $this->decide($request, $id, LeaveRequest::STATUS_REJECTED, 'LEAVE_REJECTED');
    }

    private function decide(Request $request, string $id, string $status, string $action): RedirectResponse
    {
        $actor = $request->user();

        $validated = HrValidation::leaveDecision($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $leave = LeaveRequest::find($id);
        if ($leave === null || $leave->status !== LeaveRequest::STATUS_REQUESTED) {
            return back()->with('actionError', 'leaveNotPending');
        }

        // Segregation of duties: nobody approves or rejects their own leave.
        $actorStaffId = HrQueries::getUserStaffId($actor->id);
        if (HrRoles::isSelfApproval($actorStaffId, $leave->staff_id)) {
            return back()->with('actionError', 'cannotDecideOwnLeave');
        }

        $leave->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_at' => ClinicTime::nowUtc(),
            'decision_note' => $values['note'],
        ])->save();

        Audit::write('LeaveRequest', $id, $action,
            ['status' => LeaveRequest::STATUS_REQUESTED],
            ['status' => $status, 'decisionNote' => $values['note']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }
}
