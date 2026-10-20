<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\Patients\TagColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Patient create/read/update, ported from createPatientAction,
 * updatePatientAction and the /patients pages.
 *
 * Rules under test: E.164 storage, unique phone (blocking duplicate), a
 * non-blocking similar-name confirmation, the 10-field audit snapshot, and the
 * VIEW row written when the detail page opens.
 */
class PatientCrudTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_the_create_form_renders(): void
    {
        $this->actingAs($this->owner())
            ->get('/patients/new')
            ->assertOk()
            ->assertSee(__('patients.fullName'))
            ->assertSee(__('patients.phone'));
    }

    public function test_it_creates_a_patient_normalizing_the_phone_to_e164(): void
    {
        $user = $this->owner();

        $this->actingAs($user)
            ->post('/patients', [
                'fullName' => 'Almaz Tesfaye',
                'phone' => '0911 223 344',
                'sex' => 'FEMALE',
                'birthDate' => '1990-05-04',
                'preferredLanguage' => 'am',
                'notesNonClinical' => 'Prefers morning appointments',
                'idNo' => 'ID-77',
            ])
            ->assertRedirect();

        $patient = Patient::sole();
        $this->assertSame('Almaz Tesfaye', $patient->full_name);
        $this->assertSame('+251911223344', $patient->phone_e164);
        $this->assertSame('FEMALE', $patient->sex);
        $this->assertSame('1990-05-04', $patient->birth_date->format('Y-m-d'));
        $this->assertSame('am', $patient->preferred_language);
        $this->assertSame('Prefers morning appointments', $patient->notes_non_clinical);
        $this->assertSame('ID-77', $patient->id_no);
        $this->assertTrue($patient->active);
        $this->assertSame($user->id, $patient->created_by);
    }

    public function test_it_defaults_the_language_to_the_clinic_setting(): void
    {
        Setting::create(['key' => 'default_language', 'value' => 'am']);

        $this->actingAs($this->owner())->post('/patients', [
            'fullName' => 'No Language',
            'phone' => '0911223355',
        ]);

        $this->assertSame('am', Patient::sole()->preferred_language);
    }

    public function test_it_rejects_a_missing_name(): void
    {
        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => '', 'phone' => '0911223344'])
            ->assertSessionHasErrors('fullName');

        $this->assertSame(0, Patient::count());
    }

    public function test_it_rejects_an_invalid_phone(): void
    {
        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => 'Almaz', 'phone' => '12345'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Patient::count());
    }

    public function test_a_duplicate_phone_blocks_creation(): void
    {
        $this->createPatient(['phone_e164' => '+251911223344']);

        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => 'Copycat', 'phone' => '0911223344'])
            ->assertSessionHas('duplicatePatient');

        $this->assertSame(1, Patient::count());
    }

    public function test_a_similar_name_and_birth_date_requires_confirmation(): void
    {
        $this->createPatient([
            'full_name' => 'Mekdes Alemu',
            'birth_date' => '1985-03-12',
            'phone_e164' => '+251911223300',
        ]);

        // First submit: similar person detected → re-rendered with the panel.
        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', [
                'fullName' => 'Mekides Alemu',
                'phone' => '0911223344',
                'birthDate' => '1985-03-12',
            ])
            ->assertSessionHas('similarPatients');

        $this->assertSame(1, Patient::count());
    }

    public function test_confirming_a_similar_name_creates_the_patient(): void
    {
        $this->createPatient([
            'full_name' => 'Mekdes Alemu',
            'birth_date' => '1985-03-12',
            'phone_e164' => '+251911223300',
        ]);

        $this->actingAs($this->owner())
            ->post('/patients', [
                'fullName' => 'Mekides Alemu',
                'phone' => '0911223344',
                'birthDate' => '1985-03-12',
                'confirm' => 'true',
            ])
            ->assertRedirect();

        $this->assertSame(2, Patient::count());
    }

    public function test_creation_writes_a_patient_created_audit_snapshot(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post('/patients', [
            'fullName' => 'Audited Patient',
            'phone' => '0911223344',
        ]);

        $log = AuditLog::where('action', 'PATIENT_CREATED')->sole();
        $this->assertSame('Patient', $log->entity);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame([], $log->old_json);
        $this->assertSame('Audited Patient', $log->new_json['fullName']);
        $this->assertSame('+251911223344', $log->new_json['phoneE164']);
        $this->assertFalse($log->new_json['doNotMessage']);
        $this->assertTrue($log->new_json['active']);
    }

    public function test_the_detail_page_renders_and_writes_a_view_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['full_name' => 'Viewed Patient'], $user);

        $this->actingAs($user)
            ->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertSee('Viewed Patient');

        $log = AuditLog::where('action', 'VIEW')->sole();
        $this->assertSame('Patient', $log->entity);
        $this->assertSame($patient->id, $log->entity_id);
    }

    public function test_the_edit_form_is_prefilled(): void
    {
        $patient = $this->createPatient(['full_name' => 'Editable Name']);

        $this->actingAs($this->owner())
            ->get("/patients/{$patient->id}/edit")
            ->assertOk()
            ->assertSee('Editable Name');
    }

    public function test_it_updates_a_patient_and_audits_only_changed_fields(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([
            'full_name' => 'Old Name',
            'phone_e164' => '+251911223344',
            'sex' => 'MALE',
        ], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}", [
                'fullName' => 'New Name',
                'phone' => '0911223344',
                'sex' => 'MALE',
            ])
            ->assertRedirect("/patients/{$patient->id}");

        $patient->refresh();
        $this->assertSame('New Name', $patient->full_name);

        $log = AuditLog::where('action', 'PATIENT_UPDATED')->sole();
        $this->assertSame('Old Name', $log->old_json['fullName']);
        $this->assertSame('New Name', $log->new_json['fullName']);
        // Unchanged fields are not part of the diff.
        $this->assertArrayNotHasKey('sex', $log->new_json);
    }

    public function test_updating_without_a_real_change_records_an_empty_diff(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([
            'full_name' => 'Same Name',
            'phone_e164' => '+251911223344',
            'sex' => 'MALE',
            'birth_date' => '1990-01-01',
        ], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}", [
            'fullName' => 'Same Name',
            'phone' => '0911223344',
            'sex' => 'MALE',
            'birthDate' => '1990-01-01',
        ]);

        // Legacy always calls writeAudit on update; with no changed fields the
        // row is still inserted but both diff halves are NULL.
        $log = AuditLog::where('action', 'PATIENT_UPDATED')->sole();
        $this->assertNull($log->old_json);
        $this->assertNull($log->new_json);
    }

    public function test_updating_to_another_patients_phone_is_blocked(): void
    {
        $this->createPatient(['phone_e164' => '+251911223300']);
        $patient = $this->createPatient(['phone_e164' => '+251911223344']);

        $this->actingAs($this->owner())
            ->from("/patients/{$patient->id}/edit")
            ->post("/patients/{$patient->id}", [
                'fullName' => 'Whoever',
                'phone' => '0911223300',
            ])
            ->assertSessionHas('duplicatePatient');

        $this->assertSame('+251911223344', $patient->fresh()->phone_e164);
    }

    public function test_updating_a_missing_patient_is_a_404(): void
    {
        $this->actingAs($this->owner())
            ->post('/patients/missing-id', ['fullName' => 'X', 'phone' => '0911223344'])
            ->assertNotFound();
    }

    public function test_the_detail_page_defaults_to_the_overview_tab(): void
    {
        $patient = $this->createPatient(['notes_non_clinical' => 'Non-clinical free text']);

        $this->actingAs($this->owner())
            ->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertSee('Non-clinical free text');
    }

    public function test_an_unknown_tab_falls_back_to_overview(): void
    {
        $patient = $this->createPatient(['notes_non_clinical' => 'Overview marker']);

        $this->actingAs($this->owner())
            ->get("/patients/{$patient->id}?tab=bogus")
            ->assertOk()
            ->assertSee('Overview marker');
    }

    public function test_the_detail_page_shows_the_patients_tags_and_status(): void
    {
        $patient = $this->createPatient(['full_name' => 'Tagged Patient']);
        $tag = $this->createTag('glasses', TagColors::for('glasses'));
        $patient->tags()->attach($tag->id);

        $this->actingAs($this->owner())
            ->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertSee('glasses');
    }
}
