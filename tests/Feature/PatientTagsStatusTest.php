<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tag;
use App\Models\User;
use App\Support\Patients\PatientImport;
use App\Support\Patients\TagColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The three detail-page side actions: tag replacement, active/inactive toggle
 * and stop/resume messaging, ported from setPatientTagsAction,
 * setPatientActiveAction and setPatientDoNotMessageAction.
 *
 * Rules under test: tags are normalized/deduped and carry a stable color, an
 * unchanged tag set writes no audit, no-op status toggles are silent, stop
 * messages demands a reason, and resuming is OWNER-only.
 */
class PatientTagsStatusTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_it_replaces_the_patients_tags(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $old = $this->createTag('old');
        $patient->tags()->attach($old->id);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/tags", ['tags' => 'glasses, cataract'])
            ->assertRedirect();

        $names = $patient->fresh()->tags->pluck('name')->sort()->values()->all();
        $this->assertSame(['cataract', 'glasses'], $names);
    }

    public function test_it_normalizes_and_dedupes_tag_names(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/tags", ['tags' => '  Glasses , glasses , GLASSES ,  '])
            ->assertRedirect();

        $names = $patient->fresh()->tags->pluck('name')->all();
        $this->assertSame(['glasses'], $names);
    }

    public function test_each_new_tag_receives_its_stable_color(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/tags", ['tags' => 'followup']);

        $tag = Tag::where('name', 'followup')->sole();
        $this->assertSame(TagColors::for('followup'), $tag->color);
    }

    public function test_reusing_an_existing_tag_does_not_create_a_duplicate(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $this->createTag('existing', '#123456');

        $this->actingAs($user)->post("/patients/{$patient->id}/tags", ['tags' => 'existing']);

        $this->assertSame(1, Tag::where('name', 'existing')->count());
        // The seeded color is kept (firstOrCreate does not overwrite).
        $this->assertSame('#123456', Tag::where('name', 'existing')->sole()->color);
    }

    public function test_an_unchanged_tag_set_writes_no_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $tag = $this->createTag('stable');
        $patient->tags()->attach($tag->id);

        $this->actingAs($user)->post("/patients/{$patient->id}/tags", ['tags' => 'stable']);

        $this->assertSame(0, AuditLog::where('action', 'PATIENT_TAGS_CHANGED')->count());
    }

    public function test_changing_tags_writes_a_sorted_diff_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $old = $this->createTag('zeta');
        $patient->tags()->attach($old->id);

        $this->actingAs($user)->post("/patients/{$patient->id}/tags", ['tags' => 'beta, alpha']);

        $log = AuditLog::where('action', 'PATIENT_TAGS_CHANGED')->sole();
        $this->assertSame(['zeta'], $log->old_json['tags']);
        $this->assertSame(['alpha', 'beta'], $log->new_json['tags']);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_a_tag_name_longer_than_the_limit_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $tooLong = str_repeat('a', PatientImport::MAX_TAG_LENGTH + 1);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/tags", ['tags' => $tooLong])
            ->assertSessionHasErrors('tags');

        $this->assertSame(0, $patient->fresh()->tags->count());
    }

    public function test_more_tags_than_allowed_is_rejected(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);
        $names = [];
        for ($i = 0; $i <= PatientImport::MAX_TAGS_PER_PATIENT; $i++) {
            $names[] = 'tag'.$i;
        }

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/tags", ['tags' => implode(',', $names)])
            ->assertSessionHasErrors('tags');

        $this->assertSame(0, $patient->fresh()->tags->count());
    }

    public function test_tags_on_a_missing_patient_are_a_404(): void
    {
        $this->actingAs($this->owner())
            ->post('/patients/missing/tags', ['tags' => 'x'])
            ->assertNotFound();
    }

    public function test_it_deactivates_a_patient(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['active' => true], $user);

        $this->actingAs($user)
            ->post("/patients/{$patient->id}/status", ['active' => 'false'])
            ->assertRedirect();

        $this->assertFalse($patient->fresh()->active);

        $log = AuditLog::where('action', 'PATIENT_STATUS_CHANGED')->sole();
        $this->assertTrue($log->old_json['active']);
        $this->assertFalse($log->new_json['active']);
    }

    public function test_it_reactivates_a_patient(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['active' => false], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/status", ['active' => 'true']);

        $this->assertTrue($patient->fresh()->active);
    }

    public function test_a_no_op_status_toggle_writes_no_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['active' => true], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/status", ['active' => 'true']);

        $this->assertSame(0, AuditLog::where('action', 'PATIENT_STATUS_CHANGED')->count());
        $this->assertTrue($patient->fresh()->active);
    }

    public function test_stopping_messages_requires_a_reason(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)
            ->from("/patients/{$patient->id}")
            ->post("/patients/{$patient->id}/do-not-message", ['stop' => 'true', 'reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($patient->fresh()->do_not_message);
    }

    public function test_stopping_messages_records_the_reason_and_audits(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/do-not-message", [
            'stop' => 'true',
            'reason' => 'Asked at the front desk',
        ]);

        $patient->refresh();
        $this->assertTrue($patient->do_not_message);
        $this->assertSame('Asked at the front desk', $patient->do_not_message_reason);

        $log = AuditLog::where('action', 'PATIENT_DNM_CHANGED')->sole();
        $this->assertFalse($log->old_json['doNotMessage']);
        $this->assertTrue($log->new_json['doNotMessage']);
        $this->assertSame('Asked at the front desk', $log->new_json['reason']);
    }

    public function test_reception_cannot_resume_messages_but_the_owner_can(): void
    {
        $reception = $this->createUser(['role' => 'RECEPTION']);
        $patient = $this->createPatient(['do_not_message' => true, 'do_not_message_reason' => 'x'], $reception);

        $this->actingAs($reception)
            ->post("/patients/{$patient->id}/do-not-message", ['stop' => 'false'])
            ->assertForbidden();

        $this->assertTrue($patient->fresh()->do_not_message);

        $this->actingAs($this->owner())
            ->post("/patients/{$patient->id}/do-not-message", ['stop' => 'false'])
            ->assertRedirect();

        $patient->refresh();
        $this->assertFalse($patient->do_not_message);
        $this->assertNull($patient->do_not_message_reason);
    }

    public function test_a_no_op_resume_writes_no_audit(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient([], $user);

        $this->actingAs($user)->post("/patients/{$patient->id}/do-not-message", ['stop' => 'false']);

        $this->assertSame(0, AuditLog::where('action', 'PATIENT_DNM_CHANGED')->count());
    }

    public function test_a_missing_patient_is_a_404_for_status_and_dnm(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/patients/missing/status', ['active' => 'false'])->assertNotFound();
        $this->actingAs($owner)->post('/patients/missing/do-not-message', ['stop' => 'true', 'reason' => 'x'])->assertNotFound();
    }

    public function test_the_detail_page_shows_a_stopped_messages_badge(): void
    {
        $patient = $this->createPatient(['full_name' => 'Badged Patient', 'do_not_message' => true, 'do_not_message_reason' => 'asked']);

        $this->actingAs($this->owner())
            ->get("/patients/{$patient->id}")
            ->assertOk()
            ->assertSee(__('patients.dnmYes'));
    }
}
