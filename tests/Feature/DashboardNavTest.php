<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Dashboard shell renders the role-correct navigation (legacy NAV_MODULES +
 * AppShell). OWNER sees the full sidebar; RECEPTION loses the CMS/HR/Audit
 * owner-only entries; STAFF sees only its own three links.
 */
class DashboardNavTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_the_dashboard_renders_for_every_role(): void
    {
        foreach (['OWNER', 'RECEPTION', 'STAFF'] as $role) {
            $this->actingAs($this->createUser(['role' => $role]))
                ->get('/dashboard')
                ->assertOk()
                ->assertSee(__('dashboard.title'));
        }
    }

    public function test_an_owner_sees_the_owner_only_navigation_entries(): void
    {
        $response = $this->actingAs($this->createUser(['role' => 'OWNER']))->get('/dashboard');

        $response->assertSee('href="/revenue"', false);
        $response->assertSee('href="/settings"', false);
        $response->assertSee('href="/audit"', false);
        $response->assertSee('href="/staff"', false);
        $response->assertSee('href="/dashboard/feedback"', false);
    }

    public function test_a_receptionist_sees_the_front_desk_entries_but_not_owner_ones(): void
    {
        $response = $this->actingAs($this->createUser(['role' => 'RECEPTION']))->get('/dashboard');

        $response->assertSee('href="/patients"', false);
        $response->assertSee('href="/sales"', false);
        $response->assertSee('href="/appointments"', false);
        $response->assertSee('href="/messages"', false);

        $response->assertDontSee('href="/revenue"', false);
        $response->assertDontSee('href="/settings"', false);
        $response->assertDontSee('href="/audit"', false);
        $response->assertDontSee('href="/dashboard/feedback"', false);
    }

    public function test_a_staff_member_sees_only_the_schedule_and_leave_links(): void
    {
        $response = $this->actingAs($this->createUser(['role' => 'STAFF']))->get('/dashboard');

        $response->assertSee('href="/schedule"', false);
        $response->assertSee('href="/leave"', false);
        $response->assertDontSee('href="/patients"', false);
        $response->assertDontSee('href="/sales"', false);
        $response->assertDontSee('href="/messages"', false);
    }

    public function test_the_dashboard_shows_the_users_name_and_role_label(): void
    {
        $user = $this->createUser(['role' => 'OWNER', 'name' => 'Sara Owner']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertSee('Sara Owner')
            ->assertSee(__('common.roleOwner'));
    }

    public function test_the_global_search_is_hidden_for_staff_but_present_for_reception(): void
    {
        $this->actingAs($this->createUser(['role' => 'RECEPTION']))
            ->get('/dashboard')
            ->assertSee('action="/api/search"', false);

        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get('/dashboard')
            ->assertDontSee('action="/api/search"', false);
    }
}
