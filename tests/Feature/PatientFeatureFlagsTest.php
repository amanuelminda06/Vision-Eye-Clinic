<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Models\Setting;
use App\Models\User;
use App\Support\Roles;
use App\Support\Settings;
use App\Support\Storage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The opt-in patient features: ID photos and sick leave.
 *
 * Both are OFF by default. While off the UI hides them AND the server refuses
 * every route — the setting is the control, not the button.
 *
 * ID photos are OWNER + RECEPTION only, and every view of a stored photo is
 * audited.
 */
class PatientFeatureFlagsTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => Roles::OWNER]);
    }

    private function enable(string $key): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => true]);
    }

    /**
     * A complete settings payload (the controller validates every key).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'clinic_name' => 'Vision Eye Clinic',
            'currency' => 'ETB',
            'timezone' => 'Africa/Addis_Ababa',
            'reminder_send_time' => '14:00',
            'reminder_send_by_offset_minutes' => 60,
            'followup_lead_days' => 3,
            'recall_months' => 12,
            'review_link' => '',
            'sms_mode' => 'MANUAL_LINK',
            'campaign_max_recipients' => 300,
            'digest_email' => '',
            'default_language' => 'en',
            'group_sms_max_recipients' => 15,
        ], $overrides);
    }

    private function png(int $bytes = 64): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'id.png',
            hex2bin('89504e470d0a1a0a').str_repeat("\x00", $bytes),
        );
    }

    /** A patient with a stored ID photo (the feature is enabled for it). */
    private function patientWithPhoto(): Patient
    {
        $this->enable('patient_id_photos_enabled');

        $key = 'patient-id-'.str_replace('-', '', (string) Str::uuid()).'.png';

        Storage::put($key, hex2bin('89504e470d0a1a0a').str_repeat("\x00", 32));

        return Patient::factory()->create([
            'created_by' => $this->owner()->id,
            'id_photo_url' => '/uploads/'.$key,
        ]);
    }

    // ---------------------------------------------------------------------
    // The defaults
    // ---------------------------------------------------------------------

    public function test_both_features_are_off_by_default(): void
    {
        // The setting is absent → off.
        $this->assertFalse(Settings::patientIdPhotosEnabled());
        $this->assertFalse(Settings::patientSickLeaveEnabled());

        // Even when the rows exist, the default is off.
        Setting::query()->updateOrCreate(['key' => 'patient_id_photos_enabled'], ['value' => false]);
        Setting::query()->updateOrCreate(['key' => 'patient_sick_leave_enabled'], ['value' => false]);

        $this->assertFalse(Settings::patientIdPhotosEnabled());
        $this->assertFalse(Settings::patientSickLeaveEnabled());
    }

    public function test_the_production_seed_creates_both_features_off(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(Setting::find('patient_id_photos_enabled')->value);
        $this->assertFalse(Setting::find('patient_sick_leave_enabled')->value);
    }

    // ---------------------------------------------------------------------
    // ID photos — blocked while off
    // ---------------------------------------------------------------------

    public function test_the_capture_is_refused_while_the_feature_is_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $this->actingAs($this->owner())
            ->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()])
            ->assertForbidden();

        $this->assertNull($patient->fresh()->id_photo_url);
    }

    public function test_a_photo_cannot_be_attached_at_create_time_while_off(): void
    {
        $response = $this->actingAs($this->owner())
            ->post('/patients', [
                'fullName' => 'Photo Person',
                'phone' => '0911223344',
                'sex' => 'FEMALE',
                'birthDate' => '1990-01-01',
                'preferredLanguage' => 'en',
                'consent' => 'true',
                'idPhoto' => $this->png(),
            ]);

        $response->assertSessionHasErrors('idPhoto');
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_a_photo_cannot_be_attached_at_edit_time_while_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $this->actingAs($this->owner())
            ->post("/patients/{$patient->id}", [
                'fullName' => 'Photo Person',
                'phone' => '0911223344',
                'sex' => 'FEMALE',
                'birthDate' => '1990-01-01',
                'preferredLanguage' => 'en',
                'consent' => 'true',
                'idPhoto' => $this->png(),
            ])
            ->assertSessionHasErrors('idPhoto');

        $this->assertNull($patient->fresh()->id_photo_url);
    }

    public function test_a_stored_photo_is_not_served_while_the_feature_is_off(): void
    {
        $patient = $this->patientWithPhoto();

        // The feature is turned off again.
        Setting::query()->updateOrCreate(['key' => 'patient_id_photos_enabled'], ['value' => false]);

        $key = Storage::keyFromUrl($patient->id_photo_url);

        $this->actingAs($this->owner())
            ->get("/uploads/{$key}")
            ->assertForbidden();
    }

    public function test_the_form_hides_the_photo_block_while_the_feature_is_off(): void
    {
        $response = $this->actingAs($this->owner())->get('/patients/new');

        $response->assertOk();
        $response->assertDontSee('name="idPhoto"', false);
    }

    public function test_the_form_shows_the_photo_block_when_the_feature_is_on(): void
    {
        $this->enable('patient_id_photos_enabled');

        $response = $this->actingAs($this->owner())->get('/patients/new');

        $response->assertOk();
        $response->assertSee('name="idPhoto"', false);
    }

    public function test_the_detail_page_hides_the_photo_block_while_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $response = $this->actingAs($this->owner())->get("/patients/{$patient->id}");

        $response->assertOk();
        $response->assertDontSee('name="idPhoto"', false);
    }

    // ---------------------------------------------------------------------
    // ID photos — role + audit
    // ---------------------------------------------------------------------

    public function test_staff_can_not_read_a_stored_photo(): void
    {
        $patient = $this->patientWithPhoto();

        $key = Storage::keyFromUrl($patient->id_photo_url);

        $this->actingAs($this->createUser(['role' => Roles::STAFF]))
            ->get("/uploads/{$key}")
            ->assertRedirect('/403');
    }

    public function test_staff_can_not_capture_a_photo(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $this->actingAs($this->createUser(['role' => Roles::STAFF]))
            ->post("/patients/{$patient->id}/photo", ['idPhoto' => $this->png()])
            ->assertForbidden();
    }

    public function test_every_view_of_a_stored_photo_is_audited(): void
    {
        $patient = $this->patientWithPhoto();
        $owner = $this->owner();

        $key = Storage::keyFromUrl($patient->id_photo_url);

        $this->actingAs($owner)->get("/uploads/{$key}")->assertOk();
        $this->actingAs($owner)->get("/uploads/{$key}")->assertOk();

        $rows = AuditLog::query()
            ->where('action', 'PATIENT_ID_PHOTO_VIEWED')
            ->get();

        // Every view is a row — the generic VIEW dedupe does not apply.
        $this->assertCount(2, $rows);
        $this->assertSame('Patient', (string) $rows[0]->entity);
        $this->assertSame($patient->id, (string) $rows[0]->entity_id);
        $this->assertSame($owner->id, (string) $rows[0]->user_id);
    }

    public function test_reception_can_read_a_stored_photo(): void
    {
        $patient = $this->patientWithPhoto();

        $key = Storage::keyFromUrl($patient->id_photo_url);

        $this->actingAs($this->createUser(['role' => Roles::RECEPTION]))
            ->get("/uploads/{$key}")
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // Sick leave — blocked while off
    // ---------------------------------------------------------------------

    public function test_sick_leave_writes_are_refused_while_the_feature_is_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post("/patients/{$patient->id}/sick-leave", [
                'startDate' => '2026-10-01',
                'endDate' => '2026-10-03',
                'reason' => 'Rest',
            ])
            ->assertForbidden();

        $this->assertSame(0, PatientSickLeave::query()->count());
    }

    public function test_the_sick_leave_tab_is_hidden_while_the_feature_is_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $response = $this->actingAs($this->owner())->get("/patients/{$patient->id}");

        $response->assertOk();
        $response->assertDontSee(__('patients.tabSick'));
    }

    public function test_the_sick_leave_tab_falls_back_to_the_overview_while_off(): void
    {
        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $response = $this->actingAs($this->owner())->get("/patients/{$patient->id}?tab=sick");

        $response->assertOk();
        $response->assertDontSee(__('patients.tabSick'));
        // The overview is shown instead.
        $response->assertSee(__('patients.tabOverview'));
    }

    public function test_the_sick_leave_tab_is_shown_when_the_feature_is_on(): void
    {
        $this->enable('patient_sick_leave_enabled');

        $patient = Patient::factory()->create(['created_by' => $this->owner()->id]);

        $response = $this->actingAs($this->owner())->get("/patients/{$patient->id}");

        $response->assertOk();
        $response->assertSee(__('patients.tabSick'));
    }

    // ---------------------------------------------------------------------
    // The settings screen
    // ---------------------------------------------------------------------

    public function test_the_settings_screen_offers_both_switches(): void
    {
        $response = $this->actingAs($this->owner())->get('/settings');

        $response->assertOk();
        $response->assertSee('name="patient_id_photos_enabled"', false);
        $response->assertSee('name="patient_sick_leave_enabled"', false);
    }

    public function test_the_settings_form_can_turn_both_features_on(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/settings', $this->settingsPayload([
            'patient_id_photos_enabled' => 'true',
            'patient_sick_leave_enabled' => 'true',
        ]))->assertRedirect();

        $this->assertTrue(Settings::patientIdPhotosEnabled());
        $this->assertTrue(Settings::patientSickLeaveEnabled());
    }

    public function test_the_settings_form_turns_the_features_off_again(): void
    {
        $this->enable('patient_id_photos_enabled');
        $this->enable('patient_sick_leave_enabled');

        $this->actingAs($this->owner())->post('/settings', $this->settingsPayload([
            'patient_id_photos_enabled' => 'false',
            'patient_sick_leave_enabled' => 'false',
        ]))->assertRedirect();

        $this->assertFalse(Settings::patientIdPhotosEnabled());
        $this->assertFalse(Settings::patientSickLeaveEnabled());
    }
}
