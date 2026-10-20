<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Server-side access control for the appointment + follow-up surfaces.
 * Both are OWNER + RECEPTION only — STAFF is redirected to /403.
 */
class AppointmentAccessTest extends TestCase
{
    use CreatesAppointments;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_anonymous_requests_redirect_to_the_secret_login(): void
    {
        $this->get('/appointments')->assertRedirect(login_path());
        $this->get('/appointments/new')->assertRedirect(login_path());
        $this->get('/appointments/export')->assertRedirect(login_path());
        $this->get('/follow-ups')->assertRedirect(login_path());
        $this->get('/follow-ups/new')->assertRedirect(login_path());
        $this->post('/appointments')->assertRedirect(login_path());
        $this->post('/follow-ups')->assertRedirect(login_path());
    }

    public function test_owner_and_reception_can_open_the_boards(): void
    {
        foreach ([Roles::OWNER, Roles::RECEPTION] as $role) {
            $user = $this->createUser(['role' => $role]);

            $this->actingAs($user)->get('/appointments')->assertOk();
            $this->actingAs($user->fresh())->get('/appointments/new')->assertOk();
            $this->actingAs($user->fresh())->get('/follow-ups')->assertOk();
            $this->actingAs($user->fresh())->get('/follow-ups/new')->assertOk();
        }
    }

    public function test_staff_is_blocked_from_every_appointment_surface(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staff)->get('/appointments')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/appointments/new')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/appointments/export')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/follow-ups')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/follow-ups/new')->assertRedirect('/403');
    }

    public function test_staff_mutations_are_a_hard_403(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);
        $appointment = $this->createAppointment();

        $this->actingAs($staff)->post('/appointments', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/appointments/{$appointment->id}", [])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/appointments/{$appointment->id}/status", [])->assertForbidden();
        $this->actingAs($staff->fresh())->post('/follow-ups', [])->assertForbidden();
    }

    public function test_the_navigation_hides_the_boards_from_staff(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $response = $this->actingAs($staff)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('/appointments', false);
        $response->assertDontSee('/follow-ups', false);
    }

    public function test_the_navigation_shows_the_boards_to_reception(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $response = $this->actingAs($reception)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('/appointments', false);
        $response->assertSee('/follow-ups', false);
    }
}
