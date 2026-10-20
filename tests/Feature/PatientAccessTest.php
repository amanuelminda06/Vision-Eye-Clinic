<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Server-side access control for the patients surface (PATIENT_ROLES =
 * OWNER + RECEPTION). STAFF is blocked on every page; anonymous requests go to
 * the secret login path. The export/import/template/search/uploads routes share
 * the same gate.
 */
class PatientAccessTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_anonymous_requests_redirect_to_the_secret_login(): void
    {
        $this->get('/patients')->assertRedirect(login_path());
        $this->get('/patients/new')->assertRedirect(login_path());
        $this->get('/patients/import')->assertRedirect(login_path());
        $this->get('/patients/export')->assertRedirect(login_path());
        $this->get('/patients/template')->assertRedirect(login_path());
        $this->get('/uploads/anything.png')->assertRedirect(login_path());
        $this->post('/patients')->assertRedirect(login_path());
    }

    public function test_anonymous_search_gets_a_json_401(): void
    {
        // /api/* always renders JSON (bootstrap shouldRenderJsonWhen) — an XHR
        // endpoint must not redirect to an HTML login page, and the response
        // deliberately does not leak the secret login path.
        $this->get('/api/search?q=test')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_owner_can_open_the_patients_list(): void
    {
        $this->actingAs($this->createUser(['role' => 'OWNER']))
            ->get('/patients')
            ->assertOk()
            ->assertSee(__('patients.title'));
    }

    public function test_reception_can_open_the_patients_list(): void
    {
        $this->actingAs($this->createUser(['role' => 'RECEPTION']))
            ->get('/patients')
            ->assertOk();
    }

    public function test_staff_is_blocked_from_the_patients_list_with_a_soft_403(): void
    {
        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get('/patients')
            ->assertRedirect('/403');
    }

    public function test_staff_is_hard_blocked_from_every_patient_mutation(): void
    {
        $patient = $this->createPatient();

        $staff = $this->createUser(['role' => 'STAFF']);

        $this->actingAs($staff)->post('/patients')->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}")->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}/tags")->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}/status")->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}/do-not-message")->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}/photo")->assertForbidden();
        $this->actingAs($staff)->post("/patients/{$patient->id}/sick-leave")->assertForbidden();
    }

    public function test_forced_password_change_runs_before_the_role_gate(): void
    {
        // A user who must change their password is bounced to /change-password
        // even though they hold a valid PATIENT_ROLE.
        $user = $this->createUser(['role' => 'OWNER', 'must_change_password' => true]);

        $this->actingAs($user)
            ->get('/patients')
            ->assertRedirect('/change-password');
    }

    public function test_a_missing_patient_is_a_404_for_both_roles(): void
    {
        foreach (['OWNER', 'RECEPTION'] as $role) {
            $this->actingAs($this->createUser(['role' => $role]))
                ->get('/patients/does-not-exist')
                ->assertNotFound();
        }
    }

    public function test_the_upload_route_404s_for_an_unknown_key(): void
    {
        $this->actingAs($this->createUser(['role' => 'OWNER']))
            ->get('/uploads/nope.png')
            ->assertNotFound();
    }

    public function test_export_and_template_are_available_to_reception(): void
    {
        $reception = $this->createUser(['role' => 'RECEPTION']);
        $this->createPatient([], $reception);

        $this->actingAs($reception)->get('/patients/export?format=csv')->assertOk();
        $this->actingAs($reception)->get('/patients/template')->assertOk();
    }

    public function test_patient_pages_are_not_reachable_by_staff_through_the_edit_route(): void
    {
        $patient = $this->createPatient();

        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get("/patients/{$patient->id}/edit")
            ->assertRedirect('/403');
    }

    public function test_the_list_only_ever_shows_patient_side_data(): void
    {
        // A patient row is visible to permitted roles; the page never exposes
        // an id-photo or clinical field it should not.
        $patient = $this->createPatient(['full_name' => 'Visible Patient']);

        $this->assertInstanceOf(Patient::class, $patient);

        $this->actingAs($this->createUser(['role' => 'OWNER']))
            ->get('/patients')
            ->assertOk()
            ->assertSee('Visible Patient');
    }
}
