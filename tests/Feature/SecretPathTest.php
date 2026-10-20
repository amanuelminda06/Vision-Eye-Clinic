<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Secret login path (shell spec §2 "Secret path", rule 19). The login URL only
 * exists at /login/<LOGIN_SECRET_PATH>; every other /login path is a real 404 so
 * scanners learn nothing, and there is never a redirect from a wrong secret.
 */
class SecretPathTest extends TestCase
{
    use RefreshDatabase;

    private function secret(): string
    {
        return (string) config('auth.login_secret_path');
    }

    public function test_the_bare_login_index_is_a_hard_404(): void
    {
        $this->get('/login')->assertNotFound();
    }

    public function test_a_wrong_secret_is_a_hard_404(): void
    {
        $this->get('/login/not-the-secret')->assertNotFound();
        $this->get('/login/'.strrev($this->secret()))->assertNotFound();
    }

    public function test_the_correct_secret_renders_the_login_page(): void
    {
        $this->get('/login/'.$this->secret())
            ->assertOk()
            ->assertSee(__('auth.loginTitle'));
    }

    public function test_a_wrong_secret_does_not_redirect_to_the_real_login_page(): void
    {
        $this->get('/login/wrong-secret')->assertNotFound()->assertStatus(404);
    }

    public function test_the_public_root_is_not_the_login_page(): void
    {
        $this->get('/')->assertOk()->assertDontSee(__('auth.loginTitle'));
    }
}
