<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\ClinicTime;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHr;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The weekly shift schedule: create, edit, delete, the overlap rule, the
 * overnight rule and the template generation.
 */
class ScheduleTest extends TestCase
{
    use CreatesHr;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    public function test_the_owner_creates_a_shift(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff(['full_name' => 'Dr. Abebe']);

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
            'note' => 'Front desk',
        ])->assertRedirect();

        $shift = Shift::query()->firstOrFail();

        $this->assertSame($staff->id, $shift->staff_id);
        $this->assertSame('MANUAL', (string) $shift->source);
        $this->assertSame('Front desk', $shift->note);
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-08', '09:00', self::TZ)->getTimestamp(),
            $shift->starts_at->getTimestamp(),
        );
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-08', '17:00', self::TZ)->getTimestamp(),
            $shift->ends_at->getTimestamp(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Shift',
            'entity_id' => $shift->id,
            'action' => 'SHIFT_CREATED',
        ]);
    }

    public function test_an_overnight_shift_ends_on_the_next_day(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '22:00',
            'endTime' => '06:00',
        ])->assertRedirect();

        $shift = Shift::query()->firstOrFail();

        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-09', '06:00', self::TZ)->getTimestamp(),
            $shift->ends_at->getTimestamp(),
        );
    }

    public function test_a_zero_length_shift_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '09:00',
        ])->assertSessionHasErrors('endTime');

        $this->assertSame(0, Shift::query()->count());
    }

    public function test_the_times_must_be_valid(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '9:00',
            'endTime' => '25:00',
        ])->assertSessionHasErrors(['startTime', 'endTime']);
    }

    public function test_an_overlapping_shift_for_the_same_staff_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ])->assertRedirect();

        $this->actingAs($owner->fresh())->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '16:00',
            'endTime' => '20:00',
        ])->assertSessionHas('actionError', 'shiftOverlap');

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_a_touching_shift_is_not_an_overlap(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '12:00',
        ])->assertRedirect();

        $this->actingAs($owner->fresh())->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '12:00',
            'endTime' => '15:00',
        ])->assertRedirect();

        $this->assertSame(2, Shift::query()->count());
    }

    public function test_another_staff_member_may_have_the_same_time(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $first = $this->createStaff();
        $second = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $first->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ])->assertRedirect();

        $this->actingAs($owner->fresh())->post('/schedule/shifts', [
            'staffId' => $second->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ])->assertRedirect();

        $this->assertSame(2, Shift::query()->count());
    }

    public function test_an_inactive_staff_member_cannot_be_scheduled(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff(['active' => false]);

        $this->actingAs($owner)->post('/schedule/shifts', [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ])->assertSessionHas('actionError', 'staffRequired');
    }

    public function test_the_owner_updates_a_shift(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();
        $shift = $this->createShift(['staff_id' => $staff->id, 'note' => 'Old note']);

        $this->actingAs($owner)->post("/schedule/shifts/{$shift->id}", [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-09',
            'startTime' => '10:00',
            'endTime' => '18:00',
            'note' => 'New note',
        ])->assertRedirect();

        $shift->refresh();

        $this->assertSame('New note', $shift->note);
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-09', '10:00', self::TZ)->getTimestamp(),
            $shift->starts_at->getTimestamp(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Shift',
            'entity_id' => $shift->id,
            'action' => 'SHIFT_UPDATED',
        ]);
    }

    public function test_the_shift_being_edited_does_not_clash_with_itself(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();
        $shift = $this->createShift(['staff_id' => $staff->id]);

        $this->actingAs($owner)->post("/schedule/shifts/{$shift->id}", [
            'staffId' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '10:00',
            'endTime' => '18:00',
        ])->assertRedirect();

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_the_owner_deletes_a_shift_and_it_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $shift = $this->createShift();

        $this->actingAs($owner)->post("/schedule/shifts/{$shift->id}/delete")->assertRedirect();

        $this->assertSame(0, Shift::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Shift',
            'entity_id' => $shift->id,
            'action' => 'SHIFT_DELETED',
        ]);
    }

    public function test_deleting_a_missing_shift_is_idempotent_and_audits_nothing(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/schedule/shifts/01J0000000000000000000000/delete')->assertRedirect();

        $this->assertSame(0, AuditLog::query()->where('action', 'SHIFT_DELETED')->count());
    }

    public function test_the_owner_creates_and_deletes_a_template(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->actingAs($owner)->post('/schedule/templates', [
            'staffId' => $staff->id,
            'weekday' => '4',
            'startTime' => '09:00',
            'endTime' => '17:00',
            'validFrom' => '2026-10-01',
            'validTo' => '',
        ])->assertRedirect();

        $template = ShiftTemplate::query()->firstOrFail();

        $this->assertSame(4, (int) $template->weekday);
        $this->assertNull($template->valid_to);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'ShiftTemplate',
            'entity_id' => $template->id,
            'action' => 'SHIFT_TEMPLATE_CREATED',
        ]);

        $this->actingAs($owner->fresh())->post("/schedule/templates/{$template->id}/delete")->assertRedirect();

        $this->assertSame(0, ShiftTemplate::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'ShiftTemplate',
            'entity_id' => $template->id,
            'action' => 'SHIFT_TEMPLATE_DELETED',
        ]);
    }

    public function test_a_template_range_longer_than_62_days_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/schedule/templates/apply', [
            'fromKey' => '2026-01-01',
            'toKey' => '2026-12-31',
        ])->assertSessionHasErrors('toKey');
    }

    public function test_applying_a_template_creates_the_matching_shifts(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        // 2026-10-08 is a Thursday (weekday 4).
        $this->createTemplate([
            'staff_id' => $staff->id,
            'weekday' => 4,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => '2026-10-01',
        ]);

        $this->actingAs($owner)->post('/schedule/templates/apply', [
            'fromKey' => '2026-10-08',
            'toKey' => '2026-10-21',
        ])->assertRedirect();

        $this->assertSame(2, Shift::query()->count());
        $this->assertSame('TEMPLATE', (string) Shift::query()->firstOrFail()->source);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Shift',
            'entity_id' => 'template:2026-10-08:2026-10-21',
            'action' => 'SHIFTS_GENERATED',
        ]);
    }

    public function test_applying_a_template_skips_overlapping_shifts(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->createTemplate([
            'staff_id' => $staff->id,
            'weekday' => 4,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => '2026-10-01',
        ]);

        // An existing shift on the first Thursday blocks that one only.
        $this->createShift([
            'staff_id' => $staff->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);

        $this->actingAs($owner)->post('/schedule/templates/apply', [
            'fromKey' => '2026-10-08',
            'toKey' => '2026-10-21',
        ])->assertRedirect();

        // 1 manual + 1 generated (the second Thursday).
        $this->assertSame(2, Shift::query()->count());
        $this->assertSame(1, Shift::query()->where('source', 'TEMPLATE')->count());
    }

    public function test_copy_last_week_duplicates_the_previous_week(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $staff = $this->createStaff();

        $this->createShift([
            'staff_id' => $staff->id,
            'dateKey' => '2026-10-01',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);

        // The week of 2026-10-05 (Monday) is copied from the week of 09-28.
        $this->actingAs($owner)->post('/schedule/templates/copy-last-week', [
            'weekStart' => '2026-10-05',
        ])->assertRedirect();

        $this->assertSame(2, Shift::query()->count());

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Shift',
            'entity_id' => 'copy:2026-09-28:2026-10-05',
            'action' => 'SHIFTS_COPIED',
        ]);
    }

    public function test_the_staff_member_sees_only_their_own_rows(): void
    {
        $staffRecord = $this->createStaff();
        $other = $this->createStaff();
        $user = $this->createUser(['role' => Roles::STAFF, 'staff_id' => $staffRecord->id]);

        $this->createShift([
            'staff_id' => $staffRecord->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);
        $this->createShift([
            'staff_id' => $other->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);

        $body = $this->actingAs($user)->get('/schedule?week=2026-10-08')->getContent();

        $this->assertStringContainsString($staffRecord->full_name, $body);
        $this->assertStringNotContainsString($other->full_name, $body);
    }
}
