<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Global type-ahead search, ported from the /api/search route.
 *
 * The endpoint is JSON, always no-store, and staged by length: < 2 chars is
 * empty, 2 chars touches nothing, 3+ runs the patient branch (active patients
 * only, name OR phone, 6 hits). Sales and appointments stay empty until their
 * slices land.
 */
class PatientSearchTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_a_short_query_returns_empty_groups(): void
    {
        $this->createPatient(['full_name' => 'Almaz Tesfaye']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=a')
            ->assertOk()
            ->assertJson(['patients' => [], 'sales' => [], 'appointments' => []]);
    }

    public function test_a_two_character_query_does_not_search_yet(): void
    {
        $this->createPatient(['full_name' => 'Almaz Tesfaye']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=al')
            ->assertOk()
            ->assertJson(['patients' => []]);
    }

    public function test_a_three_character_name_query_finds_matching_patients(): void
    {
        $match = $this->createPatient(['full_name' => 'Almaz Tesfaye']);
        $this->createPatient(['full_name' => 'Bekele Alemu']);

        $response = $this->actingAs($this->owner())->getJson('/api/search?q=Almaz')->assertOk();

        $response->assertJsonCount(1, 'patients');
        $response->assertJsonPath('patients.0.id', $match->id);
        $response->assertJsonPath('patients.0.title', 'Almaz Tesfaye');
        $response->assertJsonPath('patients.0.href', "/patients/{$match->id}");
    }

    public function test_search_returns_the_phone_as_the_subtitle(): void
    {
        $this->createPatient(['full_name' => 'Almaz Tesfaye', 'phone_e164' => '+251911223344']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=Almaz')
            ->assertOk()
            ->assertJsonPath('patients.0.subtitle', '+251911223344');
    }

    public function test_search_matches_a_phone_fragment(): void
    {
        $patient = $this->createPatient(['full_name' => 'Almaz Tesfaye', 'phone_e164' => '+251911223344']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=223344')
            ->assertOk()
            ->assertJsonPath('patients.0.id', $patient->id);
    }

    public function test_inactive_patients_are_never_returned(): void
    {
        $this->createPatient(['full_name' => 'Hidden Inactive', 'active' => false]);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=Hidden')
            ->assertOk()
            ->assertJson(['patients' => []]);
    }

    public function test_the_patient_branch_is_capped_at_six_hits(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createPatient(['full_name' => 'Match Number '.$i]);
        }

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=Match')
            ->assertOk()
            ->assertJsonCount(6, 'patients');
    }

    public function test_results_are_ordered_by_name(): void
    {
        $this->createPatient(['full_name' => 'Match Zeta']);
        $this->createPatient(['full_name' => 'Match Alpha']);

        $response = $this->actingAs($this->owner())->getJson('/api/search?q=Match')->assertOk();

        $response->assertJsonPath('patients.0.title', 'Match Alpha');
        $response->assertJsonPath('patients.1.title', 'Match Zeta');
    }

    public function test_like_wildcards_in_the_query_are_treated_literally(): void
    {
        $this->createPatient(['full_name' => 'Almaz Tesfaye']);

        // A bare `%` (with a leading `al`) must not match every patient.
        $this->actingAs($this->owner())
            ->getJson('/api/search?q=al%')
            ->assertOk()
            ->assertJson(['patients' => []]);
    }

    public function test_the_response_is_marked_no_store(): void
    {
        $response = $this->actingAs($this->owner())->getJson('/api/search?q=anything')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_sales_and_appointments_stay_empty_for_now(): void
    {
        $this->createPatient(['full_name' => 'Almaz Tesfaye']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q=Almaz')
            ->assertOk()
            ->assertJsonPath('sales', [])
            ->assertJsonPath('appointments', []);
    }

    public function test_staff_cannot_use_search(): void
    {
        // GET with the wrong role is a soft redirect to /403 (leaks nothing),
        // matching every other page gate — the endpoint never renders data.
        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->getJson('/api/search?q=Almaz')
            ->assertRedirect('/403');
    }

    public function test_the_query_is_trimmed(): void
    {
        $patient = $this->createPatient(['full_name' => 'Almaz Tesfaye']);

        $this->actingAs($this->owner())
            ->getJson('/api/search?q='.rawurlencode('  Almaz  '))
            ->assertOk()
            ->assertJsonPath('patients.0.id', $patient->id);
    }

    public function test_a_missing_query_is_empty_not_an_error(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/search')
            ->assertOk()
            ->assertJson(['patients' => [], 'sales' => [], 'appointments' => []]);
    }
}
