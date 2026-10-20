<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\Patients\PatientQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The patient list screen: query, id-number, date-range and status filters,
 * sortable columns, pagination and the empty-state wording. Ported from
 * listPatients and the /patients page.
 */
class PatientListTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_the_default_list_shows_the_patients_page_size(): void
    {
        $user = $this->owner();
        for ($i = 0; $i < PatientQueries::PAGE_SIZE + 5; $i++) {
            $this->createPatient(['full_name' => 'Patient '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)], $user);
        }

        $this->actingAs($user)
            ->get('/patients')
            ->assertOk()
            // Page 1 shows exactly one page worth of rows (1–20 of 25).
            ->assertSee('of 25')
            ->assertDontSee('Patient 024');
    }

    public function test_it_moves_to_the_last_page(): void
    {
        $user = $this->owner();
        for ($i = 0; $i < PatientQueries::PAGE_SIZE + 5; $i++) {
            $this->createPatient(['full_name' => 'Patient '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)], $user);
        }

        $this->actingAs($user)
            ->get('/patients?page=2')
            ->assertOk()
            ->assertSee('Patient 024');
    }

    public function test_a_page_beyond_the_end_clamps_to_the_last_page(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Only Patient'], $user);

        $this->actingAs($user)
            ->get('/patients?page=999')
            ->assertOk()
            ->assertSee('Only Patient');
    }

    public function test_the_name_filter_matches_case_insensitively_for_ascii(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Almaz Tesfaye'], $user);
        $this->createPatient(['full_name' => 'Bekele Alemu'], $user);

        $this->actingAs($user)
            ->get('/patients?q=almaz')
            ->assertOk()
            ->assertSee('Almaz Tesfaye')
            ->assertDontSee('Bekele Alemu');
    }

    public function test_the_name_filter_matches_a_phone_fragment(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Almaz Tesfaye', 'phone_e164' => '+251911223344'], $user);
        $this->createPatient(['full_name' => 'Bekele Alemu', 'phone_e164' => '+251911555000'], $user);

        $this->actingAs($user)
            ->get('/patients?q=223344')
            ->assertOk()
            ->assertSee('Almaz Tesfaye')
            ->assertDontSee('Bekele Alemu');
    }

    public function test_the_id_number_filter_matches_an_exact_id(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Has Id', 'id_no' => 'ID-77'], $user);
        $this->createPatient(['full_name' => 'No Id'], $user);

        $this->actingAs($user)
            ->get('/patients?idNo=ID-77')
            ->assertOk()
            ->assertSee('Has Id')
            ->assertDontSee('No Id');
    }

    public function test_the_status_filter_splits_active_and_inactive(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Active Patient', 'active' => true], $user);
        $this->createPatient(['full_name' => 'Inactive Patient', 'active' => false], $user);

        $this->actingAs($user)
            ->get('/patients?status=true')
            ->assertOk()
            ->assertSee('Active Patient')
            ->assertDontSee('Inactive Patient');

        $this->actingAs($user)
            ->get('/patients?status=false')
            ->assertOk()
            ->assertSee('Inactive Patient')
            ->assertDontSee('Active Patient');
    }

    public function test_the_date_range_filter_narrows_by_creation_date(): void
    {
        $user = $this->owner();
        $old = $this->createPatient(['full_name' => 'Old Patient'], $user);
        $old->forceFill(['created_at' => '2024-01-01 08:00:00'])->save();

        $new = $this->createPatient(['full_name' => 'New Patient'], $user);
        $new->forceFill(['created_at' => '2026-01-01 08:00:00'])->save();

        $this->actingAs($user)
            ->get('/patients?dateFrom=2025-01-01&dateTo=2026-12-31')
            ->assertOk()
            ->assertSee('New Patient')
            ->assertDontSee('Old Patient');
    }

    public function test_sorting_by_name_descending_reverses_the_order(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Alpha Patient'], $user);
        $this->createPatient(['full_name' => 'Zeta Patient'], $user);

        $body = $this->actingAs($user)->get('/patients?sort=fullName&dir=desc')->assertOk()->getContent();

        $this->assertGreaterThan(strpos($body, 'Zeta Patient'), strpos($body, 'Alpha Patient'));
    }

    public function test_an_unknown_sort_column_falls_back_to_name(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Only Patient'], $user);

        $this->actingAs($user)
            ->get('/patients?sort=evil;drop&dir=asc')
            ->assertOk()
            ->assertSee('Only Patient');
    }

    public function test_an_unknown_direction_falls_back_to_ascending(): void
    {
        $this->assertSame('asc', PatientQueries::sortDir('sideways'));
        $this->assertSame('desc', PatientQueries::sortDir('desc'));
    }

    public function test_no_matching_rows_shows_the_filtered_empty_state(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Someone'], $user);

        $this->actingAs($user)
            ->get('/patients?q=zzzzz')
            ->assertOk()
            ->assertSee(__('common.noResults'));
    }

    public function test_a_completely_empty_list_shows_the_empty_state(): void
    {
        $this->actingAs($this->owner())
            ->get('/patients')
            ->assertOk()
            ->assertSee(__('common.empty'));
    }

    public function test_the_list_never_exposes_a_clinical_field(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Listed', 'notes_non_clinical' => 'Secret non-clinical note'], $user);

        // The list view does not render patient notes at all.
        $this->actingAs($user)
            ->get('/patients')
            ->assertOk()
            ->assertDontSee('Secret non-clinical note');
    }

    public function test_the_row_link_opens_the_patient_detail_page(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['full_name' => 'Linked Patient'], $user);

        $this->actingAs($user)
            ->get('/patients')
            ->assertOk()
            ->assertSee("/patients/{$patient->id}", false);
    }

    public function test_the_list_shows_a_stop_messages_badge(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Opted Out', 'do_not_message' => true, 'do_not_message_reason' => 'asked'], $user);

        $this->actingAs($user)
            ->get('/patients')
            ->assertOk()
            ->assertSee(__('patients.dnmYes'));
    }
}
