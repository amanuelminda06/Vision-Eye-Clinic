<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHr;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Server-side access control for the HR surface.
 *
 * - /schedule and /leave are open to every role.
 * - /leave/calendar is OWNER + RECEPTION.
 * - /leave/inbox and every shift/template mutation is OWNER-only.
 */
class HrAccessTest extends TestCase
{
    use CreatesHr;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_anonymous_requests_redirect_to_the_secret_login(): void
    {
        $this->get('/schedule')->assertRedirect(login_path());
        $this->get('/leave')->assertRedirect(login_path());
        $this->get('/leave/inbox')->assertRedirect(login_path());
        $this->get('/leave/calendar')->assertRedirect(login_path());
        $this->get('/leave/export')->assertRedirect(login_path());
        $this->post('/leave')->assertRedirect(login_path());
    }

    public function test_every_role_can_open_the_schedule_and_own_leave(): void
    {
        foreach ([Roles::OWNER, Roles::RECEPTION, Roles::STAFF] as $role) {
            $user = $this->createUser(['role' => $role]);

            $this->actingAs($user)->get('/schedule')->assertOk();
            $this->actingAs($user->fresh())->get('/leave')->assertOk();
        }
    }

    public function test_the_team_calendar_is_owner_and_reception_only(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($owner)->get('/leave/calendar')->assertOk();
        $this->actingAs($reception->fresh())->get('/leave/calendar')->assertOk();
        $this->actingAs($staff->fresh())->get('/leave/calendar')->assertRedirect('/403');
    }

    public function test_the_inbox_is_owner_only(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($owner)->get('/leave/inbox')->assertOk();
        $this->actingAs($reception->fresh())->get('/leave/inbox')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/leave/inbox')->assertRedirect('/403');
    }

    public function test_only_the_owner_can_mutate_shifts(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $staff = $this->createUser(['role' => Roles::STAFF]);
        $shift = $this->createShift();

        $this->actingAs($reception)->post('/schedule/shifts', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post('/schedule/shifts', [])->assertForbidden();
        $this->actingAs($reception->fresh())->post("/schedule/shifts/{$shift->id}", [])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/schedule/shifts/{$shift->id}/delete")->assertForbidden();
    }

    public function test_only_the_owner_can_mutate_templates(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $staff = $this->createUser(['role' => Roles::STAFF]);
        $template = $this->createTemplate();

        $this->actingAs($reception)->post('/schedule/templates', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post('/schedule/templates', [])->assertForbidden();
        $this->actingAs($reception->fresh())->post("/schedule/templates/{$template->id}/delete")->assertForbidden();
        $this->actingAs($staff->fresh())->post('/schedule/templates/apply', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post('/schedule/templates/copy-last-week', [])->assertForbidden();
    }

    public function test_only_the_owner_can_decide_leave(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $staff = $this->createUser(['role' => Roles::STAFF]);
        $leave = $this->createLeaveRequest();

        $this->actingAs($reception)->post("/leave/{$leave->id}/approve", ['leaveId' => $leave->id])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/leave/{$leave->id}/reject", ['leaveId' => $leave->id])->assertForbidden();
    }

    public function test_the_owner_nav_shows_the_hr_surfaces(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="/schedule"', false);
        $response->assertSee('href="/leave"', false);
        $response->assertSee('href="/leave/inbox"', false);
        $response->assertSee('href="/leave/calendar"', false);
    }

    public function test_the_staff_nav_hides_the_inbox_and_the_calendar(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $response = $this->actingAs($staff)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="/schedule"', false);
        $response->assertSee('href="/leave"', false);
        $response->assertDontSee('href="/leave/inbox"', false);
        $response->assertDontSee('href="/leave/calendar"', false);
    }
}
