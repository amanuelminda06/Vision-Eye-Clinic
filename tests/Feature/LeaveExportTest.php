<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Support\Patients\Csv;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHr;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The leave export: OWNER + RECEPTION get the team history, STAFF only their
 * own rows. The download is audited as EXPORTED.
 */
class LeaveExportTest extends TestCase
{
    use CreatesHr;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_csv_export_streams_an_attachment_with_the_legacy_header_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staffRecord = $this->createStaff(['full_name' => 'Exported Staff']);
        $this->createLeaveRequest(['staff_id' => $staffRecord->id]);

        $response = $this->actingAs($owner)->get('/leave/export?format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="leave-', (string) $response->headers->get('Content-Disposition'));

        $body = $response->getContent();

        $this->assertStringContainsString(
            'id,staff,type,startDate,endDate,halfDay,reason,status,decisionNote,createdAt',
            $body,
        );
        $this->assertStringContainsString('Exported Staff', $body);
    }

    public function test_the_export_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/leave/export?format=csv')->assertOk();

        $audit = AuditLog::query()->where('action', 'EXPORTED')->firstOrFail();

        $this->assertSame('leaveRequest', $audit->entity);
        $this->assertSame('export', $audit->entity_id);
        $this->assertSame('all', $audit->new_json['status']);
        $this->assertSame('csv', $audit->new_json['format']);
    }

    public function test_the_status_filter_narrows_the_export(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $approvedStaff = $this->createStaff(['full_name' => 'Approved Person']);
        $requestedStaff = $this->createStaff(['full_name' => 'Requested Person']);

        $this->createLeaveRequest([
            'staff_id' => $approvedStaff->id,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);
        $this->createLeaveRequest([
            'staff_id' => $requestedStaff->id,
            'status' => LeaveRequest::STATUS_REQUESTED,
        ]);

        $body = $this->actingAs($owner->fresh())
            ->get('/leave/export?format=csv&status=APPROVED')
            ->getContent();

        $this->assertStringContainsString('Approved Person', $body);
        $this->assertStringNotContainsString('Requested Person', $body);
    }

    public function test_an_unknown_status_is_ignored(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staffRecord = $this->createStaff(['full_name' => 'Any Person']);
        $this->createLeaveRequest(['staff_id' => $staffRecord->id]);

        $body = $this->actingAs($owner->fresh())
            ->get('/leave/export?format=csv&status=BOGUS')
            ->getContent();

        $this->assertStringContainsString('Any Person', $body);
    }

    public function test_staff_only_ever_see_their_own_rows(): void
    {
        $mine = $this->createStaff(['full_name' => 'My Own Leave']);
        $theirs = $this->createStaff(['full_name' => 'Someone Elses Leave']);
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $mine->id]);

        $this->createLeaveRequest(['staff_id' => $mine->id]);
        $this->createLeaveRequest(['staff_id' => $theirs->id]);

        $body = $this->actingAs($user)->get('/leave/export?format=csv')->getContent();

        $this->assertStringContainsString('My Own Leave', $body);
        $this->assertStringNotContainsString('Someone Elses Leave', $body);
    }

    public function test_a_staff_member_without_a_staff_link_exports_nothing(): void
    {
        $user = $this->createUser(['role' => Roles::STAFF]);
        $this->createLeaveRequest();

        $body = $this->actingAs($user)->get('/leave/export?format=csv')->getContent();

        $rows = Csv::parseCsv($body);
        $this->assertCount(1, $rows);
    }

    public function test_the_date_range_switches_to_the_team_query(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $inside = $this->createStaff(['full_name' => 'Inside Range']);
        $outside = $this->createStaff(['full_name' => 'Outside Range']);

        $this->createLeaveRequest([
            'staff_id' => $inside->id,
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-09',
        ]);
        $this->createLeaveRequest([
            'staff_id' => $outside->id,
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-21',
        ]);

        $body = $this->actingAs($owner->fresh())
            ->get('/leave/export?format=csv&from=2026-10-01&to=2026-10-31')
            ->getContent();

        $this->assertStringContainsString('Inside Range', $body);
        $this->assertStringNotContainsString('Outside Range', $body);
    }

    public function test_xlsx_export_streams_a_spreadsheet(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createLeaveRequest();

        $response = $this->actingAs($owner->fresh())->get('/leave/export?format=xlsx')->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertSame('PK', substr((string) $response->getContent(), 0, 2));
    }
}
