<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FollowUp;
use App\Support\ClinicTime;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The follow-up board: the tabs, the create form, the due-date presets and
 * the mark-done / cancel actions.
 */
class FollowUpTest extends TestCase
{
    use CreatesAppointments;
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    public function test_the_owner_creates_a_follow_up_with_a_preset_due_date(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();
        $todayKey = ClinicTime::clinicDateKey(now(), self::TZ);

        $this->actingAs($owner)->post('/follow-ups', [
            'patientId' => $patient->id,
            'preset' => '2w',
            'dueDate' => '',
            'reason' => 'Repeat eye test',
        ])->assertRedirect();

        $followUp = FollowUp::query()->firstOrFail();

        $this->assertSame($patient->id, $followUp->patient_id);
        $this->assertSame(FollowUp::STATUS_OPEN, (string) $followUp->status);
        $this->assertSame(ClinicTime::dateKeyAdd($todayKey, 14), $followUp->due_date->format('Y-m-d'));

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'FollowUp',
            'entity_id' => $followUp->id,
            'action' => 'FOLLOW_UP_CREATED',
        ]);
    }

    public function test_the_custom_preset_needs_a_due_date(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/follow-ups', [
            'patientId' => $patient->id,
            'preset' => 'custom',
            'dueDate' => '',
        ])->assertSessionHasErrors('dueDate');
    }

    public function test_the_custom_preset_uses_the_given_date(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/follow-ups', [
            'patientId' => $patient->id,
            'preset' => 'custom',
            'dueDate' => '2026-12-01',
        ])->assertRedirect();

        $this->assertSame('2026-12-01', FollowUp::query()->firstOrFail()->due_date->format('Y-m-d'));
    }

    public function test_the_preset_must_be_known(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/follow-ups', [
            'patientId' => $patient->id,
            'preset' => 'bogus',
        ])->assertSessionHasErrors('preset');
    }

    public function test_the_patient_is_required(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/follow-ups', [
            'patientId' => '',
            'preset' => '2w',
        ])->assertSessionHasErrors('patientId');
    }

    public function test_an_open_follow_up_can_be_marked_done(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $followUp = $this->createFollowUp();

        $this->actingAs($owner)->post("/follow-ups/{$followUp->id}/status", [
            'id' => $followUp->id,
            'status' => 'DONE',
        ])->assertRedirect();

        $this->assertSame(FollowUp::STATUS_DONE, (string) $followUp->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'FollowUp',
            'entity_id' => $followUp->id,
            'action' => 'FOLLOW_UP_STATUS_CHANGED',
        ]);
    }

    public function test_a_closed_follow_up_cannot_change_status(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $followUp = $this->createFollowUp(['status' => FollowUp::STATUS_DONE]);

        $this->actingAs($owner)->post("/follow-ups/{$followUp->id}/status", [
            'id' => $followUp->id,
            'status' => 'CANCELLED',
        ])->assertSessionHas('actionError', 'invalidTransition');

        $this->assertSame(FollowUp::STATUS_DONE, (string) $followUp->fresh()->status);
    }

    public function test_the_open_tab_shows_only_open_follow_ups(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $open = $this->createFollowUp(['reason' => 'Still open follow up']);
        $done = $this->createFollowUp(['status' => FollowUp::STATUS_DONE, 'reason' => 'Already done follow up']);

        $body = $this->actingAs($owner)->get('/follow-ups?status=open')->getContent();

        $this->assertStringContainsString('Still open follow up', $body);
        $this->assertStringNotContainsString('Already done follow up', $body);
    }

    public function test_the_overdue_tab_only_shows_past_due_dates(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $overdue = $this->createFollowUp([
            'due_date' => now()->subDays(3)->format('Y-m-d'),
            'reason' => 'Overdue follow up',
        ]);
        $future = $this->createFollowUp([
            'due_date' => now()->addDays(3)->format('Y-m-d'),
            'reason' => 'Future follow up',
        ]);

        $body = $this->actingAs($owner->fresh())->get('/follow-ups?status=overdue')->getContent();

        $this->assertStringContainsString('Overdue follow up', $body);
        $this->assertStringNotContainsString('Future follow up', $body);
    }

    public function test_the_patient_filter_narrows_the_list(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $wanted = $this->createPatient(['full_name' => 'Wanted Follow Up']);
        $other = $this->createPatient(['full_name' => 'Other Follow Up']);

        $this->createFollowUp(['patient_id' => $wanted->id, 'reason' => 'Wanted reason']);
        $this->createFollowUp(['patient_id' => $other->id, 'reason' => 'Other reason']);

        $body = $this->actingAs($owner->fresh())
            ->get('/follow-ups?status=open&patient='.$wanted->id)
            ->getContent();

        $this->assertStringContainsString('Wanted reason', $body);
        $this->assertStringNotContainsString('Other reason', $body);
    }

    public function test_staff_cannot_reach_the_follow_up_board(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staff)->get('/follow-ups')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->post('/follow-ups', [])->assertForbidden();
    }
}
