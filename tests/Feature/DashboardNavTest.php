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

        $response->assertSee('/revenue', false);
        $response->assertSee('/settings', false);
        $response->assertSee('/audit', false);
        $response->assertSee('/staff', false);
        $response->assertSee('/dashboard/feedback', false);
    }

    public function test_a_receptionist_sees_the_front_desk_entries_but_not_owner_ones(): void
    {
        $response = $this->actingAs($this->createUser(['role' => 'RECEPTION']))->get('/dashboard');

        $response->assertSee('/patients', false);
        $response->assertSee('/sales', false);
        $response->assertSee('/appointments', false);
        $response->assertSee('/messages', false);

        $response->assertDontSee('/revenue', false);
        $response->assertDontSee('/settings', false);
        $response->assertDontSee('/audit', false);
        $response->assertDontSee('/dashboard/feedback', false);
    }

    public function test_a_staff_member_sees_only_the_schedule_and_leave_links(): void
    {
        $response = $this->actingAs($this->createUser(['role' => 'STAFF']))->get('/dashboard');

        $response->assertSee('/schedule', false);
        $response->assertSee('/leave', false);
        $response->assertDontSee('/patients', false);
        $response->assertDontSee('/sales', false);
        $response->assertDontSee('/messages', false);
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
