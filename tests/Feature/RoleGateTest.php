<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Server-side role gate (EnsureRole, port of requireRole / requirePageRole).
 *
 * GET with the wrong role → soft redirect to /403; a mutation with the wrong
 * role → 403; no session → the secret login path. The forced-password-change
 * check runs BEFORE the role check (spec ordering).
 *
 * The app only exposes role-gated routes in later slices, so this test
 * registers throwaway probe routes that use the real middleware stack.
 */
class RoleGateTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth', 'password.changed', 'role:OWNER'])->group(function () {
            Route::get('/__probe/owner', fn () => response('owner-ok'))->name('probe.owner');
            Route::post('/__probe/owner', fn () => response('owner-post-ok'));
        });

        Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
            Route::get('/__probe/frontdesk', fn () => response('frontdesk-ok'));
        });
    }

    public function test_an_anonymous_request_is_sent_to_the_secret_login_path(): void
    {
        $this->get('/__probe/owner')->assertRedirect(login_path());
    }

    public function test_an_anonymous_post_is_also_redirected_to_login(): void
    {
        $this->post('/__probe/owner')->assertRedirect(login_path());
    }

    public function test_a_matching_role_is_allowed_through(): void
    {
        $this->actingAs($this->createUser(['role' => 'OWNER']))
            ->get('/__probe/owner')
            ->assertOk()
            ->assertSee('owner-ok');
    }

    public function test_a_wrong_role_on_a_get_gets_a_soft_403_redirect(): void
    {
        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get('/__probe/owner')
            ->assertRedirect('/403');
    }

    public function test_a_wrong_role_on_a_mutation_is_a_hard_403(): void
    {
        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->post('/__probe/owner')
            ->assertForbidden();
    }

    public function test_a_multi_role_gate_accepts_each_allowed_role(): void
    {
        $this->actingAs($this->createUser(['role' => 'OWNER']))
            ->get('/__probe/frontdesk')->assertOk();

        $this->actingAs($this->createUser(['role' => 'RECEPTION']))
            ->get('/__probe/frontdesk')->assertOk();
    }

    public function test_a_multi_role_gate_rejects_an_unlisted_role(): void
    {
        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get('/__probe/frontdesk')
            ->assertRedirect('/403');
    }

    public function test_the_forced_password_change_check_runs_before_the_role_check(): void
    {
        // A STAFF user who must change their password is bounced to
        // /change-password, never to /403 — ordering matters.
        $this->actingAs($this->createUser(['role' => 'STAFF', 'must_change_password' => true]))
            ->get('/__probe/owner')
            ->assertRedirect(route('password.change'));
    }

    public function test_the_friendly_403_page_is_reachable_without_a_session(): void
    {
        $this->get('/403')
            ->assertStatus(403)
            ->assertSee(__('forbidden.title'));
    }
}
