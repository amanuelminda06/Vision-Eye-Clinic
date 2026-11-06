<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Family links (the guardian field): a child can be saved with no phone of
 * their own, messages are then addressed to the guardian's number, and the
 * whole family is findable by the guardian's number.
 *
 * Rules under test:
 * - phone required UNLESS a guardian is given; a NULL phone never trips the
 *   duplicate rule while every real number stays unique.
 * - the lookup accepts a phone, a system ID or an explicit id, and refuses
 *   self-links and loops.
 * - every link change writes PATIENT_GUARDIAN_CHANGED, and the guardian id
 *   rides along in the PATIENT_CREATED / PATIENT_UPDATED snapshots.
 * - the guardian's number is READ from the guardian's row (never copied), so
 *   the SMS hand-off for a child opens on the guardian's number.
 * - list + type-ahead show the guardian's name and number for each child.
 */
class PatientGuardianTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    /** The guardian every child in these tests points at. */
    private function guardian(): Patient
    {
        return $this->createPatient([
            'full_name' => 'Bekele Alemu',
            'phone_e164' => '+251911223344',
            'age' => 40,
            'sex' => 'MALE',
        ]);
    }

    /** A linked child: keeps their own identity, stores no phone. */
    private function child(string $name, Patient $guardian, array $overrides = [], ?User $actor = null): Patient
    {
        return $this->createPatient(array_merge([
            'full_name' => $name,
            'phone_e164' => null,
            'age' => 7,
            'sex' => 'FEMALE',
            'guardian_id' => $guardian->id,
            'relationship' => Patient::RELATIONSHIP_GUARDIAN,
        ], $overrides), $actor);
    }

    public function test_a_child_can_be_created_with_a_guardian_and_no_phone(): void
    {
        $guardian = $this->guardian();

        $this->actingAs($this->owner())
            ->post('/patients', [
                'fullName' => 'Kid Alemu',
                'phone' => '',
                'guardian' => '0911223344',
                'age' => '7',
                'sex' => 'FEMALE',
            ])
            ->assertRedirect(route('patients.index'));

        $child = Patient::where('full_name', 'Kid Alemu')->sole();

        $this->assertNull($child->phone_e164);
        $this->assertSame($guardian->id, $child->guardian_id);
        $this->assertSame(Patient::RELATIONSHIP_GUARDIAN, $child->relationship);
        // Each family member keeps their own identity…
        $this->assertSame(7, $child->age);
        $this->assertSame('FEMALE', $child->sex);
        $this->assertNotSame($guardian->full_name, $child->full_name);
        // …while the number messages reach is read from the guardian's row.
        $this->assertSame('+251911223344', $child->effectivePhoneE164());
    }

    public function test_the_guardian_can_be_looked_up_by_system_id_or_explicit_id(): void
    {
        $guardian = $this->createPatient([
            'full_name' => 'System Guardian',
            'phone_e164' => '+251911223344',
            'system_id' => 'AA0001',
        ]);
        $user = $this->owner();

        // The human lookup field also understands a (case-insensitive) system ID.
        $this->actingAs($user)
            ->post('/patients', ['fullName' => 'System Child', 'guardian' => 'aa0001'])
            ->assertRedirect(route('patients.index'));

        $bySystemId = Patient::where('full_name', 'System Child')->sole();
        $this->assertSame($guardian->id, $bySystemId->guardian_id);

        // Programmatic posts may carry the patient id directly.
        $this->actingAs($user->fresh())
            ->post('/patients', ['fullName' => 'Explicit Child', 'guardianId' => $guardian->id])
            ->assertRedirect(route('patients.index'));

        $byId = Patient::where('full_name', 'Explicit Child')->sole();
        $this->assertSame($guardian->id, $byId->guardian_id);
        $this->assertNull($byId->phone_e164);
    }

    public function test_the_created_audit_snapshot_carries_the_guardian(): void
    {
        $guardian = $this->guardian();
        $user = $this->owner();

        $this->actingAs($user)->post('/patients', [
            'fullName' => 'Audited Child',
            'guardian' => '0911223344',
        ])->assertRedirect(route('patients.index'));

        $child = Patient::where('full_name', 'Audited Child')->sole();
        $log = AuditLog::where('action', 'PATIENT_CREATED')->sole();

        $this->assertSame('Patient', $log->entity);
        $this->assertSame($child->id, $log->entity_id);
        $this->assertSame($guardian->id, $log->new_json['guardianId']);
        // The child stores no number, so the NULL stays out of the diff —
        // the guardian id alone records where messages will be sent.
        $this->assertArrayNotHasKey('phoneE164', $log->new_json);
        $this->assertNull($child->phone_e164);
    }

    public function test_linking_and_unlinking_a_guardian_is_audited(): void
    {
        $guardian = $this->guardian();
        $user = $this->owner();
        $child = $this->createPatient([
            'full_name' => 'Link Child',
            'phone_e164' => '+251911223355',
        ], $user);

        $this->assertSame(0, AuditLog::where('action', 'PATIENT_GUARDIAN_CHANGED')->count());

        $this->actingAs($user)
            ->post("/patients/{$child->id}", [
                'fullName' => 'Link Child',
                'phone' => '0911223355',
                'guardian' => '0911223344',
            ])
            ->assertRedirect("/patients/{$child->id}");

        $this->assertSame($guardian->id, $child->fresh()->guardian_id);

        $link = AuditLog::where('action', 'PATIENT_GUARDIAN_CHANGED')->sole();
        $this->assertSame('Patient', $link->entity);
        $this->assertSame($child->id, $link->entity_id);
        $this->assertNull($link->old_json['guardianId']);
        $this->assertSame($guardian->id, $link->new_json['guardianId']);

        // Unlinking: with no guardian the phone is mandatory again, so the
        // child has to bring their own number with them.
        $this->actingAs($user->fresh())
            ->post("/patients/{$child->id}", [
                'fullName' => 'Link Child',
                'phone' => '0911223355',
            ])
            ->assertRedirect("/patients/{$child->id}");

        $this->assertNull($child->fresh()->guardian_id);
        $this->assertSame(2, AuditLog::where('action', 'PATIENT_GUARDIAN_CHANGED')->count());
        $this->assertTrue(
            AuditLog::where('action', 'PATIENT_GUARDIAN_CHANGED')->get()->contains(
                fn (AuditLog $row): bool => $row->old_json['guardianId'] === $guardian->id
                    && $row->new_json['guardianId'] === null,
            ),
        );
    }

    public function test_editing_a_child_without_touching_the_link_writes_no_link_audit(): void
    {
        $guardian = $this->guardian();
        $child = $this->child('Quiet Child', $guardian, [], $this->owner());

        $this->actingAs($this->owner())
            ->post("/patients/{$child->id}", [
                'fullName' => 'Quiet Child Renamed',
                'age' => '8',
                'guardian' => '0911223344',
            ])
            ->assertRedirect("/patients/{$child->id}");

        $this->assertSame('Quiet Child Renamed', $child->fresh()->full_name);
        $this->assertSame(0, AuditLog::where('action', 'PATIENT_GUARDIAN_CHANGED')->count());
    }

    public function test_an_unknown_guardian_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => 'Nobody', 'guardian' => '0911999999'])
            ->assertSessionHasErrors(['guardian' => 'invalidGuardian']);

        $this->assertSame(0, Patient::where('full_name', 'Nobody')->count());
    }

    public function test_a_patient_cannot_be_their_own_guardian(): void
    {
        $patient = $this->createPatient([
            'full_name' => 'Self Guardian',
            'phone_e164' => '+251911223344',
        ]);

        $this->actingAs($this->owner())
            ->from("/patients/{$patient->id}/edit")
            ->post("/patients/{$patient->id}", [
                'fullName' => 'Self Guardian',
                'phone' => '0911223344',
                'guardian' => '0911223344',
            ])
            ->assertSessionHasErrors(['guardian' => 'guardianSelf']);

        $this->assertNull($patient->fresh()->guardian_id);
    }

    public function test_a_guardian_loop_is_refused(): void
    {
        $a = $this->createPatient(['full_name' => 'Parent A', 'phone_e164' => '+251911223300']);
        $b = $this->createPatient(['full_name' => 'Parent B', 'phone_e164' => '+251911223344']);
        $user = $this->owner();

        $this->actingAs($user)->post("/patients/{$a->id}", [
            'fullName' => 'Parent A',
            'phone' => '0911223300',
            'guardian' => '0911223344',
        ])->assertRedirect("/patients/{$a->id}");

        $this->assertSame($b->id, $a->fresh()->guardian_id);

        // A looking after B while B already looks after A is a loop.
        $this->actingAs($user->fresh())
            ->from("/patients/{$b->id}/edit")
            ->post("/patients/{$b->id}", [
                'fullName' => 'Parent B',
                'phone' => '0911223344',
                'guardian' => '0911223300',
            ])
            ->assertSessionHasErrors(['guardian' => 'guardianCycle']);

        $this->assertNull($b->fresh()->guardian_id);
    }

    public function test_the_phone_is_still_required_without_a_guardian(): void
    {
        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => 'No Phone No Guardian'])
            ->assertSessionHasErrors(['phone' => 'required']);

        $this->assertSame(0, Patient::count());
    }

    public function test_a_duplicate_phone_still_blocks_a_patient_with_their_own_number(): void
    {
        $this->guardian();

        $this->actingAs($this->owner())
            ->from('/patients/new')
            ->post('/patients', ['fullName' => 'Copycat', 'phone' => '0911223344'])
            ->assertSessionHas('duplicatePatient');

        $this->assertSame(1, Patient::count());
    }

    public function test_two_children_without_phones_never_collide(): void
    {
        $this->guardian();
        $user = $this->owner();

        $this->actingAs($user)
            ->post('/patients', ['fullName' => 'No Phone One', 'guardian' => '0911223344'])
            ->assertRedirect(route('patients.index'));

        $this->actingAs($user->fresh())
            ->post('/patients', ['fullName' => 'No Phone Two', 'guardian' => '0911223344'])
            ->assertRedirect(route('patients.index'));

        // Guardian + two phone-less children: no duplicate warning, no block.
        $this->assertSame(3, Patient::count());
        $this->assertSame(2, Patient::whereNull('phone_e164')->count());
    }

    public function test_a_message_for_a_child_is_addressed_to_the_guardians_number(): void
    {
        $owner = $this->owner();
        $guardian = $this->guardian();
        $child = $this->child('Msg Child', $guardian, [], $owner);

        $task = MessageTask::create([
            'patient_id' => $child->id,
            'template_key' => 'APPT_REMINDER',
            'source_type' => 'APPOINTMENT',
            'source_id' => 'src-1',
            'language' => 'en',
            'rendered_message' => 'Hello there',
            'due_at' => now(),
            'status' => MessageTask::STATUS_PENDING,
        ]);

        // Eligibility reads the guardian's number, so the task is sendable.
        $this->actingAs($owner)
            ->post("/messages/{$task->id}/send")
            ->assertRedirect(route('messages.sms', ['id' => $task->id]));

        $this->assertSame(MessageTask::STATUS_SENT, (string) $task->fresh()->status);

        $this->actingAs($owner->fresh())
            ->get(route('messages.sms', ['id' => $task->id]))
            ->assertOk()
            ->assertSee('sms:+251911223344?body=Hello%20there', false);

        // Nothing was copied onto the child's row when the link was read.
        $this->assertNull($child->fresh()->phone_e164);
    }

    public function test_searching_the_guardians_number_returns_the_whole_family_in_the_list(): void
    {
        $guardian = $this->guardian();
        $one = $this->child('Family One', $guardian);
        $two = $this->child('Family Two', $guardian, ['sex' => 'MALE', 'age' => 9]);

        $response = $this->actingAs($this->owner())
            ->get('/patients?q=0911223344')
            ->assertOk();

        $response->assertSee('Bekele Alemu');
        $response->assertSee('Family One');
        $response->assertSee('Family Two');
        $response->assertSee('+251911223344');

        // A name query for one child still renders the guardian's column.
        $this->actingAs($this->owner())
            ->get('/patients?q='.rawurlencode($one->full_name))
            ->assertOk()
            ->assertSee('Bekele Alemu')
            ->assertSee('+251911223344');

        $this->assertSame($guardian->id, $two->fresh()->guardian_id);
    }

    public function test_the_typeahead_returns_the_family_with_a_guardian_subtitle(): void
    {
        $guardian = $this->guardian();
        $one = $this->child('Family One', $guardian);
        $two = $this->child('Family Two', $guardian);

        $response = $this->actingAs($this->owner())
            ->getJson('/api/search?q=0911223344')
            ->assertOk();

        $hits = collect($response->json('patients'));
        $this->assertCount(3, $hits);

        $guardianHit = $hits->firstWhere('id', $guardian->id);
        $this->assertSame('+251911223344', $guardianHit['subtitle']);

        $expected = __t('patients.guardianHit', ['name' => $guardian->full_name]).' · +251911223344';
        $this->assertSame($expected, $hits->firstWhere('id', $one->id)['subtitle']);
        $this->assertSame($expected, $hits->firstWhere('id', $two->id)['subtitle']);
    }

    public function test_the_detail_page_shows_the_guardian_and_the_reachable_number(): void
    {
        $guardian = $this->guardian();
        $child = $this->child('Detail Child', $guardian);

        $this->actingAs($this->owner())
            ->get("/patients/{$child->id}")
            ->assertOk()
            ->assertSee('Detail Child')
            ->assertSee('Bekele Alemu')
            ->assertSee('+251911223344')
            // The child has no number of their own: the page says whose is used.
            ->assertSee(__('patients.phoneViaGuardian'));
    }
}
