<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHr;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Leave: the request flow, the approval inbox, cancellation, the balance card
 * and the segregation of duties (nobody decides their own leave).
 */
class LeaveRequestTest extends TestCase
{
    use CreatesHr;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_a_staff_member_requests_leave_for_themselves(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'ANNUAL',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-10',
            'halfDay' => 'false',
            'reason' => 'Family event',
        ])->assertRedirect();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertSame($staffRecord->id, $leave->staff_id);
        $this->assertSame(LeaveRequest::STATUS_REQUESTED, (string) $leave->status);
        $this->assertSame('Family event', $leave->reason);
        $this->assertFalse((bool) $leave->half_day);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'LeaveRequest',
            'entity_id' => $leave->id,
            'action' => 'LEAVE_REQUESTED',
        ]);
    }

    public function test_a_half_day_request_is_stored(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'SICK',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
            'halfDay' => 'true',
        ])->assertRedirect();

        $this->assertTrue((bool) LeaveRequest::query()->firstOrFail()->half_day);
    }

    public function test_the_end_date_must_not_be_before_the_start(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'ANNUAL',
            'startDate' => '2026-10-10',
            'endDate' => '2026-10-08',
        ])->assertSessionHasErrors('endDate');
    }

    public function test_the_leave_type_must_be_known(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'BOGUS',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
        ])->assertSessionHasErrors('type');
    }

    public function test_a_request_without_a_staff_link_is_refused(): void
    {
        $user = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'ANNUAL',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
        ])->assertSessionHas('actionError', 'staffRequired');
    }

    public function test_a_reception_request_ignores_the_staff_picker(): void
    {
        $other = $this->createStaff();
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $own = $this->createStaff();

        // The RECEPTION account has a staff link, but a submitted staffId is
        // ignored — everyone requests for themselves unless the OWNER acts.
        $reception->forceFill(['staff_id' => $own->id])->save();

        $this->actingAs($reception)->post('/leave', [
            'staffId' => $other->id,
            'type' => 'ANNUAL',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
        ])->assertRedirect();

        $this->assertSame($own->id, LeaveRequest::query()->firstOrFail()->staff_id);
    }

    public function test_the_owner_may_request_leave_for_another_staff_member(): void
    {
        $other = $this->createStaff();
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/leave', [
            'staffId' => $other->id,
            'type' => 'ANNUAL',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
        ])->assertRedirect();

        $this->assertSame($other->id, LeaveRequest::query()->firstOrFail()->staff_id);
    }

    public function test_a_request_overlapping_an_approved_leave_is_rejected(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->createLeaveRequest([
            'staff_id' => $staffRecord->id,
            'status' => LeaveRequest::STATUS_APPROVED,
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-12',
        ]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'ANNUAL',
            'startDate' => '2026-10-11',
            'endDate' => '2026-10-15',
        ])->assertSessionHas('actionError', 'leaveOverlap');

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_a_pending_leave_does_not_block_a_new_request(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->createLeaveRequest([
            'staff_id' => $staffRecord->id,
            'status' => LeaveRequest::STATUS_REQUESTED,
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-12',
        ]);

        $this->actingAs($user)->post('/leave', [
            'type' => 'ANNUAL',
            'startDate' => '2026-10-11',
            'endDate' => '2026-10-15',
        ])->assertRedirect();

        $this->assertSame(2, LeaveRequest::query()->count());
    }

    public function test_the_owner_approves_a_request(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $leave = $this->createLeaveRequest();

        $this->actingAs($owner)->post("/leave/{$leave->id}/approve", [
            'leaveId' => $leave->id,
            'note' => 'Enjoy the break',
        ])->assertRedirect();

        $leave->refresh();

        $this->assertSame(LeaveRequest::STATUS_APPROVED, (string) $leave->status);
        $this->assertSame($owner->id, $leave->decided_by);
        $this->assertSame('Enjoy the break', $leave->decision_note);
        $this->assertNotNull($leave->decided_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'LeaveRequest',
            'entity_id' => $leave->id,
            'action' => 'LEAVE_APPROVED',
        ]);
    }

    public function test_the_owner_rejects_a_request(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $leave = $this->createLeaveRequest();

        $this->actingAs($owner)->post("/leave/{$leave->id}/reject", [
            'leaveId' => $leave->id,
            'note' => 'Too busy that week',
        ])->assertRedirect();

        $leave->refresh();

        $this->assertSame(LeaveRequest::STATUS_REJECTED, (string) $leave->status);
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'LeaveRequest',
            'entity_id' => $leave->id,
            'action' => 'LEAVE_REJECTED',
        ]);
    }

    public function test_nobody_can_decide_their_own_leave(): void
    {
        $staffRecord = $this->createStaff();
        $owner = $this->createUser(['role' => Roles::OWNER, 'staff_id' => $staffRecord->id]);
        $leave = $this->createLeaveRequest(['staff_id' => $staffRecord->id]);

        $this->actingAs($owner)->post("/leave/{$leave->id}/approve", [
            'leaveId' => $leave->id,
        ])->assertSessionHas('actionError', 'cannotDecideOwnLeave');

        $this->assertSame(LeaveRequest::STATUS_REQUESTED, (string) $leave->fresh()->status);
    }

    public function test_a_non_pending_request_cannot_be_decided(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $leave = $this->createLeaveRequest(['status' => LeaveRequest::STATUS_APPROVED]);

        $this->actingAs($owner)->post("/leave/{$leave->id}/approve", [
            'leaveId' => $leave->id,
        ])->assertSessionHas('actionError', 'leaveNotPending');
    }

    public function test_the_requester_cancels_their_own_pending_request(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);
        $leave = $this->createLeaveRequest(['staff_id' => $staffRecord->id]);

        $this->actingAs($user)->post("/leave/{$leave->id}/cancel", [
            'leaveId' => $leave->id,
        ])->assertRedirect();

        $leave->refresh();

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, (string) $leave->status);

        $audit = AuditLog::query()->where('action', 'LEAVE_CANCELLED')->firstOrFail();
        $this->assertSame('REQUESTED', $audit->old_json['status']);
        $this->assertSame('CANCELLED', $audit->new_json['status']);
    }

    public function test_a_staff_member_cannot_cancel_someone_elses_request(): void
    {
        $staffRecord = $this->createStaff();
        $other = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);
        $leave = $this->createLeaveRequest(['staff_id' => $other->id]);

        $this->actingAs($user)->post("/leave/{$leave->id}/cancel", [
            'leaveId' => $leave->id,
        ])->assertSessionHas('actionError', 'cannotCancel');

        $this->assertSame(LeaveRequest::STATUS_REQUESTED, (string) $leave->fresh()->status);
    }

    public function test_a_staff_member_cannot_cancel_an_approved_request(): void
    {
        $staffRecord = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);
        $leave = $this->createLeaveRequest([
            'staff_id' => $staffRecord->id,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->actingAs($user)->post("/leave/{$leave->id}/cancel", [
            'leaveId' => $leave->id,
        ])->assertSessionHas('actionError', 'cannotCancel');
    }

    public function test_the_owner_cancels_an_approved_request(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $leave = $this->createLeaveRequest(['status' => LeaveRequest::STATUS_APPROVED]);

        $this->actingAs($owner)->post("/leave/{$leave->id}/cancel", [
            'leaveId' => $leave->id,
        ])->assertRedirect();

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, (string) $leave->fresh()->status);
    }

    public function test_a_rejected_request_cannot_be_cancelled(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $leave = $this->createLeaveRequest(['status' => LeaveRequest::STATUS_REJECTED]);

        $this->actingAs($owner)->post("/leave/{$leave->id}/cancel", [
            'leaveId' => $leave->id,
        ])->assertSessionHas('actionError', 'leaveNotPending');
    }

    public function test_the_inbox_lists_the_pending_requests_only(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $pending = $this->createLeaveRequest();
        $this->createLeaveRequest(['status' => LeaveRequest::STATUS_APPROVED]);

        $body = $this->actingAs($owner)->get('/leave/inbox')->getContent();

        $this->assertStringContainsString($pending->id, $body);
        $this->assertStringNotContainsString($this->createLeaveRequest(['status' => LeaveRequest::STATUS_REJECTED])->id, $body);
    }

    public function test_the_balance_card_counts_approved_annual_leave(): void
    {
        $staffRecord = $this->createStaff(['annual_leave_days' => 20]);
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->createLeaveRequest([
            'staff_id' => $staffRecord->id,
            'type' => 'ANNUAL',
            'status' => LeaveRequest::STATUS_APPROVED,
            'start_date' => now()->format('Y').'-03-02',
            'end_date' => now()->format('Y').'-03-04',
        ]);

        $body = $this->actingAs($user)->get('/leave')->getContent();

        // Entitlement 20, taken 3 → remaining 17.
        $this->assertStringContainsString('20', $body);
        $this->assertStringContainsString('17', $body);
    }

    public function test_the_balance_card_is_unset_without_an_entitlement(): void
    {
        $staffRecord = $this->createStaff(['annual_leave_days' => null]);
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $response = $this->actingAs($user)->get('/leave');

        $response->assertOk();
        $response->assertSee(__('hr.balanceUnset'));
    }

    public function test_the_team_calendar_lists_requested_and_approved_leave(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $approvedStaff = $this->createStaff(['full_name' => 'Approved Person']);
        $rejectedStaff = $this->createStaff(['full_name' => 'Rejected Person']);

        $this->createLeaveRequest([
            'staff_id' => $approvedStaff->id,
            'status' => LeaveRequest::STATUS_APPROVED,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);
        $this->createLeaveRequest([
            'staff_id' => $rejectedStaff->id,
            'status' => LeaveRequest::STATUS_REJECTED,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
        ]);

        $body = $this->actingAs($owner)->get('/leave/calendar')->getContent();

        $this->assertStringContainsString('Approved Person', $body);
        $this->assertStringNotContainsString('Rejected Person', $body);
    }
}
