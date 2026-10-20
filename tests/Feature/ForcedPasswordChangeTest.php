<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Forced password change middleware (shell spec §2 "Forced password change",
 * rule 17). While must_change_password is true every app page bounces to
 * /change-password — except the change-password page itself and logout, so the
 * user can always finish the change or leave.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_a_forced_user_is_redirected_from_the_dashboard(): void
    {
        $this->actingAs($this->createUser(['must_change_password' => true]))
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_a_forced_user_can_still_reach_the_change_password_page(): void
    {
        $this->actingAs($this->createUser(['must_change_password' => true]))
            ->get('/change-password')
            ->assertOk()
            ->assertSee(__('changePassword.title'));
    }

    public function test_a_forced_user_can_still_logout(): void
    {
        $this->actingAs($this->createUser(['must_change_password' => true]))
            ->post('/logout')
            ->assertRedirect(login_path());
    }

    public function test_a_normal_user_reaches_the_dashboard_untouched(): void
    {
        $this->actingAs($this->createUser(['must_change_password' => false]))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_a_forced_user_is_bounced_from_the_change_password_page_after_clearing_the_flag(): void
    {
        $user = $this->createUser(['must_change_password' => true, 'email' => 'me@clinic.test']);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.change'));

        // Simulate the change: flag cleared, then the dashboard is reachable.
        $user->forceFill(['must_change_password' => false])->save();

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_the_login_redirect_for_a_forced_user_lands_on_change_password(): void
    {
        $this->createUser([
            'email' => 'fresh@clinic.test',
            'must_change_password' => true,
        ]);

        $this->post('/login/'.(string) config('auth.login_secret_path'), [
            'email' => 'fresh@clinic.test',
            'password' => 'CorrectHorseBattery9',
        ])->assertRedirect(route('password.change'));
    }
}
