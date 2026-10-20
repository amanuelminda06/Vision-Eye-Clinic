<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Hr\HrRoles;
use App\Support\Hr\HrValidation;
use App\Support\Hr\Leave;
use App\Support\Roles;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "My leave": the request form, the annual balance card and the request table.
 * Ported from src/modules/hr/actions.ts and the /leave page.
 *
 * Rules preserved:
 * - Everyone requests for themselves; only the OWNER may request for another
 *   staff member (a RECEPTION/STAFF `staffId` is ignored).
 * - A request overlapping an existing APPROVED leave for the same person is
 *   rejected.
 * - Leave is created as REQUESTED — no balance check happens.
 */
class LeaveController extends Controller
{
    /** GET /leave — my leave. */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $myStaffId = HrQueries::getUserStaffId($actor->id);
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);
        $year = (int) substr($todayKey, 0, 4);

        $balance = $myStaffId === null ? null : HrQueries::getLeaveBalance($myStaffId, $year);

        return response()->view('hr.leave', [
            'myStaffId' => $myStaffId,
            'balance' => $balance,
            'requests' => $myStaffId === null ? [] : HrQueries::listLeaveForStaff($myStaffId),
            'canRequestForOthers' => HrRoles::canRequestForOthers($actor->role),
            'staffOptions' => HrRoles::canRequestForOthers($actor->role) ? HrQueries::listStaffOptions() : collect(),
            'year' => $year,
            'todayKey' => $todayKey,
            'timezone' => $tz,
        ]);
    }

    /** POST /leave — request leave. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = HrValidation::leaveRequest($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        // Everyone requests for themselves unless the OWNER acts for another.
        $actorStaffId = HrQueries::getUserStaffId($actor->id);
        $staffId = $actorStaffId;

        if (HrRoles::canRequestForOthers($actor->role) && $values['staffId'] !== null) {
            $staffId = $values['staffId'];
        }

        if ($staffId === null) {
            return back()->with('actionError', 'staffRequired')->withInput();
        }

        $staff = Staff::find($staffId);
        if ($staff === null) {
            return back()->with('actionError', 'staffRequired')->withInput();
        }

        // Only an APPROVED leave blocks a new request.
        $approved = LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'staffId' => $leave->staff_id,
                'type' => (string) $leave->type,
                'status' => (string) $leave->status,
                'startDate' => $leave->start_date,
                'endDate' => $leave->end_date,
                'halfDay' => (bool) $leave->half_day,
            ])->all();

        $clash = Leave::findOverlappingLeave($approved, [
            'staffId' => $staffId,
            'startDate' => $values['startDate'],
            'endDate' => $values['endDate'],
        ]);

        if ($clash !== null) {
            return back()->with('actionError', 'leaveOverlap')->withInput();
        }

        $created = LeaveRequest::create([
            'staff_id' => $staffId,
            'type' => $values['type'],
            'start_date' => $values['startDate'],
            'end_date' => $values['endDate'],
            'half_day' => $values['halfDay'],
            'reason' => $values['reason'],
            'status' => LeaveRequest::STATUS_REQUESTED,
            'created_by' => $actor->id,
        ]);

        Audit::write('LeaveRequest', $created->id, 'LEAVE_REQUESTED', null, [
            'staff' => $staff->full_name,
            'type' => $values['type'],
            'startDate' => $values['startDate'],
            'endDate' => $values['endDate'],
            'halfDay' => $values['halfDay'],
            'reason' => $values['reason'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /leave/{id}/cancel — cancel a leave request. */
    public function cancel(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $validated = HrValidation::leaveDecision($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $leave = LeaveRequest::find($id);
        if ($leave === null) {
            return back()->with('actionError', 'unknownError');
        }

        if ($leave->status !== LeaveRequest::STATUS_REQUESTED
            && $leave->status !== LeaveRequest::STATUS_APPROVED) {
            return back()->with('actionError', 'leaveNotPending');
        }

        $actorStaffId = HrQueries::getUserStaffId($actor->id);
        $isSelf = $actorStaffId !== null && $actorStaffId === $leave->staff_id;
        $isOwner = $actor->role === Roles::OWNER;

        // The OWNER may cancel anything; a staff member only their own
        // still-pending request.
        if (! $isOwner && ! ($isSelf && $leave->status === LeaveRequest::STATUS_REQUESTED)) {
            return back()->with('actionError', 'cannotCancel');
        }

        $priorStatus = (string) $leave->status;

        $leave->forceFill([
            'status' => LeaveRequest::STATUS_CANCELLED,
            'decided_by' => $actor->id,
            'decided_at' => ClinicTime::nowUtc(),
        ])->save();

        Audit::write('LeaveRequest', $id, 'LEAVE_CANCELLED',
            ['status' => $priorStatus],
            ['status' => LeaveRequest::STATUS_CANCELLED, 'note' => $values['note']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }
}
