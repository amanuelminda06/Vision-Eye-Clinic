<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PatientSickLeave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Patient sick-leave records, ported from the sick-leave actions and tab.
 *
 * Sick leave is issued BY the clinic FOR a patient and is non-clinical free
 * text. It is also the module's only hard delete — the row is removed but a
 * PATIENT_SICK_LEAVE_DELETED audit carrying the full old snapshot remains.
 */
class PatientSickLeaveTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_the_sick_leave_tab_renders_for_a_patient(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->get("/patients/{$patient->id}?tab=sick")
            ->assertOk()
            ->assertSee(__('patients.sickLeaveTitle'))
            ->assertSee(__('patients.sickEmpty'));
    }

    public function test_it_records_a_sick_leave_row_and_audits_it(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/sick-leave", [
                'startDate' => '2026-01-05',
                'endDate' => '2026-01-07',
                'reason' => 'Post-op rest',
                'note' => 'Issue a follow-up slip',
            ])
            ->assertRedirect("/patients/{$patient->id}?tab=sick");

        $row = PatientSickLeave::sole();
        $this->assertSame($patient->id, $row->patient_id);
        $this->assertSame('2026-01-05', $row->start_date->format('Y-m-d'));
        $this->assertSame('2026-01-07', $row->end_date->format('Y-m-d'));
        $this->assertSame('Post-op rest', $row->reason);
        $this->assertSame($user->id, $row->recorded_by);

        $log = AuditLog::where('action', 'PATIENT_SICK_LEAVE_CREATED')->sole();
        $this->assertSame('PatientSickLeave', $log->entity);
        // CREATE passes only `new`: old_json is the empty diff object, not null.
        $this->assertSame([], $log->old_json);
        $this->assertSame('Post-op rest', $log->new_json['reason']);
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}?tab=sick")
            ->post("/patients/{$patient->id}/sick-leave", [
                'startDate' => '2026-02-10',
                'endDate' => '2026-02-01',
            ])
            ->assertSessionHasErrors('endDate');

        $this->assertSame(0, PatientSickLeave::count());
    }

    public function test_a_missing_start_date_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}?tab=sick")
            ->post("/patients/{$patient->id}/sick-leave", ['startDate' => '', 'endDate' => '2026-01-01'])
            ->assertSessionHasErrors('startDate');

        $this->assertSame(0, PatientSickLeave::count());
    }

    public function test_it_updates_a_sick_leave_row_and_audits_the_diff(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $row = $this->createSickLeave($patient, $user, [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-02',
            'reason' => 'Flu',
        ]);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/sick-leave/{$row->id}", [
                'startDate' => '2026-03-01',
                'endDate' => '2026-03-04',
                'reason' => 'Severe flu',
            ])
            ->assertRedirect("/patients/{$patient->id}?tab=sick");

        $row->refresh();
        $this->assertSame('2026-03-04', $row->end_date->format('Y-m-d'));
        $this->assertSame('Severe flu', $row->reason);

        $log = AuditLog::where('action', 'PATIENT_SICK_LEAVE_UPDATED')->sole();
        $this->assertSame('2026-03-02', $log->old_json['endDate']);
        $this->assertSame('2026-03-04', $log->new_json['endDate']);
        $this->assertSame('Flu', $log->old_json['reason']);
    }

    public function test_a_record_can_only_be_edited_from_its_own_patients_page(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $other = $this->createPatient([], $user);
        $row = $this->createSickLeave($patient, $user);

        $this->actingAs($user)
            ->post("/patients/{$other->id}/sick-leave/{$row->id}", [
                'startDate' => '2026-01-01',
                'endDate' => '2026-01-02',
            ])
            ->assertForbidden();
    }

    public function test_updating_a_missing_row_is_a_404(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/sick-leave/missing-id", [
                'startDate' => '2026-01-01',
                'endDate' => '2026-01-02',
            ])
            ->assertNotFound();
    }

    public function test_it_hard_deletes_a_row_but_keeps_a_snapshot_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $row = $this->createSickLeave($patient, $user, [
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-03',
            'reason' => 'Migraine',
            'note' => 'Keep the record for a week',
        ]);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/sick-leave/{$row->id}/delete")
            ->assertRedirect("/patients/{$patient->id}?tab=sick");

        $this->assertSame(0, PatientSickLeave::count());

        $log = AuditLog::where('action', 'PATIENT_SICK_LEAVE_DELETED')->sole();
        $this->assertSame($patient->id, $log->old_json['patientId']);
        $this->assertSame('2026-04-01', $log->old_json['startDate']);
        $this->assertSame('Migraine', $log->old_json['reason']);
        // DELETE passes only `old`: new_json is the empty diff object, not null.
        $this->assertSame([], $log->new_json);
    }

    public function test_deleting_a_missing_row_is_a_silent_redirect(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/sick-leave/missing-id/delete")
            ->assertRedirect("/patients/{$patient->id}?tab=sick");

        $this->assertSame(0, AuditLog::where('action', 'PATIENT_SICK_LEAVE_DELETED')->count());
    }

    public function test_a_missing_patient_blocks_recording_sick_leave(): void
    {
        $user = $this->owner();

        $this->actingAs($user)
            ->from('/patients/new')
            ->post('/patients/missing-id/sick-leave', [
                'startDate' => '2026-01-01',
                'endDate' => '2026-01-02',
            ])
            ->assertSessionHasErrors('patientId');

        $this->assertSame(0, PatientSickLeave::count());
    }

    public function test_the_sick_tab_lists_recorded_rows_newest_first(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $this->createSickLeave($patient, $user, ['start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'reason' => 'Older']);
        $this->createSickLeave($patient, $user, ['start_date' => '2026-06-01', 'end_date' => '2026-06-02', 'reason' => 'Newer']);

        $body = $this->actingAs($user)->get("/patients/{$patient->id}?tab=sick")->assertOk()->getContent();

        $this->assertStringContainsString('Newer', $body);
        $this->assertStringContainsString('Older', $body);
        $this->assertLessThan(strpos($body, 'Older'), strpos($body, 'Newer'));
    }

    public function test_the_edit_link_prefills_the_form(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $row = $this->createSickLeave($patient, $user, ['reason' => 'Editable reason']);

        $this->actingAs($user)
            ->get("/patients/{$patient->id}?tab=sick&edit={$row->id}")
            ->assertOk()
            ->assertSee('Editable reason')
            ->assertSee(__('patients.sickLeaveEdit'));
    }
}
