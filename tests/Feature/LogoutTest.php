<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Logout (shell spec §2 "Logout"): clears the session and lands on the secret
 * login URL — never `/`. No audit row exists for logout.
 */
class LogoutTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_logout_clears_the_session_and_lands_on_the_secret_login_path(): void
    {
        $this->actingAs($this->createUser())
            ->post('/logout')
            ->assertRedirect(login_path());

        $this->assertGuest();
    }

    public function test_logout_never_redirects_to_the_public_root(): void
    {
        $response = $this->actingAs($this->createUser())->post('/logout');

        $this->assertNotSame('/', $response->headers->get('Location'));
    }

    public function test_logout_writes_no_audit_row(): void
    {
        $this->actingAs($this->createUser())->post('/logout');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_logout_requires_a_session(): void
    {
        $this->post('/logout')->assertRedirect(login_path());
    }

    public function test_a_logged_out_user_cannot_reach_the_dashboard(): void
    {
        $this->actingAs($this->createUser())->post('/logout');

        $this->get('/dashboard')->assertRedirect(login_path());
    }
}
