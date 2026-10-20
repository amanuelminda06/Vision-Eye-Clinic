<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\LoginPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Login flow — port of verifyCredentials (auth/services/verify-credentials.ts)
 * against shell spec §2 "Credential verification — exact order".
 *
 * The suite asserts the exact order of checks, the audit rows, the lockout
 * maths, and the generic error that never reveals which check failed.
 */
class LoginTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const PASSWORD = 'CorrectHorseBattery9';

    protected function setUp(): void
    {
        parent::setUp();

        // The production credentials seed accounts are not present in tests
        // (RefreshDatabase-style :memory: DB), but the cache is process-wide:
        // reset the buckets so rate-limit state never leaks between tests.
        LoginPolicy::resetRateLimits();
    }

    private function postLogin(array $data = [], array $headers = [])
    {
        return $this->post('/login/'.(string) config('auth.login_secret_path'), $data, $headers);
    }

    // ---------------------------------------------------------------- success

    public function test_a_valid_login_authenticates_and_lands_on_the_dashboard(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test', 'role' => 'OWNER']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_is_trimmed_and_lowercased_before_lookup(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => '  OWNER@Clinic.Test  ', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(auth()->check());
    }

    public function test_a_user_pending_a_password_change_is_sent_to_change_password(): void
    {
        $this->createUser([
            'email' => 'new@clinic.test',
            'must_change_password' => true,
        ]);

        $this->postLogin(['email' => 'new@clinic.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('password.change'));
    }

    public function test_success_resets_the_failure_counter_and_stamps_last_login(): void
    {
        $user = $this->createUser([
            'email' => 'owner@clinic.test',
            'failed_login_count' => 3,
            'locked_until' => null,
        ]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->failed_login_count);
        $this->assertNull($fresh->locked_until);
        $this->assertNotNull($fresh->last_login_at);
    }

    public function test_success_writes_a_login_success_audit_row_with_the_actor(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $row = AuditLog::where('action', 'LOGIN_SUCCESS')->sole();
        $this->assertSame('User', $row->entity);
        $this->assertSame($user->id, $row->entity_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNull($row->old_json);
        $this->assertNull($row->new_json);
    }

    public function test_the_session_id_is_regenerated_on_login(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        // Establish a session with a GET, capture its id, then log in and prove
        // the id changed (session-fixation defence via regenerate()).
        $this->get('/');
        $originalId = session()->getId();

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $this->assertAuthenticated();
        $this->assertNotSame(
            $originalId,
            session()->getId(),
            'The session id must change on login.'
        );
    }

    // --------------------------------------------------------------- failures

    public function test_a_wrong_password_returns_the_generic_error(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['login' => 'invalidCredentials'])
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_a_wrong_password_keeps_the_typed_email(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['login'])
            ->assertSessionHasInput('email', 'owner@clinic.test');

        $this->assertNull(session('_old_input.password'), 'The password must never be flashed back.');
    }

    public function test_an_unknown_email_fails_with_the_same_generic_error(): void
    {
        $this->postLogin(['email' => 'nobody@clinic.test', 'password' => 'whatever-pass'])
            ->assertSessionHasErrors(['login' => 'invalidCredentials']);

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_audited_against_a_pseudo_entity_id(): void
    {
        $this->postLogin(['email' => 'Nobody@Clinic.test', 'password' => 'whatever-pass']);

        $row = AuditLog::where('action', 'LOGIN_FAILED')->sole();
        $this->assertNull($row->user_id);
        $this->assertSame('email:nobody@clinic.test', $row->entity_id);
        $this->assertSame(['reason' => 'unknownEmail'], $row->new_json);
    }

    public function test_a_wrong_password_increments_the_failure_counter(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => 'nope']);

        $fresh = $user->fresh();
        $this->assertSame(1, $fresh->failed_login_count);
        $this->assertNull($fresh->locked_until);
    }

    public function test_a_wrong_password_is_audited_with_the_invalid_password_reason(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => 'nope']);

        $row = AuditLog::where('action', 'LOGIN_FAILED')->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->id, $row->entity_id);
        $this->assertSame(['reason' => 'invalidPassword'], $row->new_json);
    }

    // ---------------------------------------------------------------- lockout

    public function test_the_fifth_failure_locks_the_account_for_fifteen_minutes(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test', 'failed_login_count' => 4]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => 'nope']);

        $fresh = $user->fresh();
        $this->assertSame(5, $fresh->failed_login_count);
        $this->assertNotNull($fresh->locked_until);
        $this->assertTrue(
            $fresh->locked_until->greaterThan(now()->addMinutes(14)),
            'The lock must last roughly 15 minutes.'
        );
    }

    public function test_a_locked_account_is_audited_with_the_locked_reason_on_a_correct_password(): void
    {
        $user = $this->createUser([
            'email' => 'owner@clinic.test',
            'failed_login_count' => 5,
            'locked_until' => now()->addMinutes(10),
        ]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $this->assertGuest();

        $row = AuditLog::where('action', 'LOGIN_FAILED')->sole();
        $this->assertSame(['reason' => 'locked'], $row->new_json);
        $this->assertSame(5, $user->fresh()->failed_login_count, 'A locked attempt must not touch the counter.');
    }

    public function test_a_locked_account_rejects_even_the_correct_password(): void
    {
        $this->createUser([
            'email' => 'owner@clinic.test',
            'locked_until' => now()->addMinute(),
        ]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['login' => 'invalidCredentials']);

        $this->assertGuest();
    }

    public function test_an_expired_lock_allows_a_correct_password_through(): void
    {
        $this->createUser([
            'email' => 'owner@clinic.test',
            'failed_login_count' => 5,
            'locked_until' => now()->subMinute(),
        ]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    // ---------------------------------------------------------------- inactive

    public function test_an_inactive_account_is_rejected_even_with_the_correct_password(): void
    {
        $this->createUser(['email' => 'owner@clinic.test', 'active' => false]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['login' => 'invalidCredentials']);

        $this->assertGuest();
    }

    public function test_an_inactive_account_is_audited_with_the_inactive_reason(): void
    {
        $this->createUser(['email' => 'owner@clinic.test', 'active' => false]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $row = AuditLog::where('action', 'LOGIN_FAILED')->sole();
        $this->assertSame(['reason' => 'inactive'], $row->new_json);
    }

    // ------------------------------------------------------------- empty input

    public function test_empty_input_fails_without_touching_the_database_or_audit(): void
    {
        $this->postLogin(['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['login' => 'invalidCredentials']);

        $this->assertGuest();
        $this->assertSame(0, AuditLog::count(), 'Empty input must not be audited.');
    }

    public function test_a_missing_email_alone_still_short_circuits(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(['password' => self::PASSWORD])->assertSessionHasErrors(['login']);
        $this->postLogin(['email' => 'owner@clinic.test', 'password' => ''])->assertSessionHasErrors(['login']);

        $this->assertGuest();
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, $this->createProbeUser()->fresh()->failed_login_count);
    }

    /** Fresh account used only to prove empty input never increments a counter. */
    private function createProbeUser(): User
    {
        return User::where('email', 'owner@clinic.test')->firstOrFail();
    }

    // -------------------------------------------------------------- ratelimit

    public function test_a_full_rate_limit_bucket_blocks_login_without_an_audit_row(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        // Tests run without proxy headers → the client IP is null → key 'unknown'.
        Cache::put('login_rate:'.hash('sha256', 'unknown'), [
            'count' => LoginPolicy::RATE_LIMIT_MAX,
            'reset_at' => time() + 300,
        ], 300);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['login' => 'invalidCredentials']);

        $this->assertGuest();
        $this->assertSame(0, AuditLog::count(), 'A rate-limited attempt must not be audited.');
        $this->assertSame(0, $this->createProbeUser()->fresh()->failed_login_count);
    }

    public function test_a_blocked_attempt_does_not_consume_the_bucket_further(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);
        $key = 'login_rate:'.hash('sha256', 'unknown');
        Cache::put($key, ['count' => 40, 'reset_at' => time() + 300], 300);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        // Blocked → count must not grow (blocked attempts are free).
        $this->assertSame(40, Cache::get($key)['count']);
    }

    public function test_empty_input_does_not_consume_a_rate_limit_slot(): void
    {
        // Empty input short-circuits BEFORE the rate limiter (spec order).
        $this->postLogin(['email' => '', 'password' => '']);

        $this->assertFalse(
            Cache::has('login_rate:'.hash('sha256', 'unknown')),
            'Empty input must not create a rate-limit bucket.'
        );
    }

    // -------------------------------------------------------------- client ip

    public function test_the_first_x_forwarded_for_hop_is_used_for_the_rate_limit_key(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $key = 'login_rate:'.hash('sha256', '203.0.113.9');
        Cache::put($key, ['count' => 40, 'reset_at' => time() + 300], 300);

        $this->postLogin(
            ['email' => 'owner@clinic.test', 'password' => self::PASSWORD],
            ['X-Forwarded-For' => '203.0.113.9, 10.0.0.1'],
        )->assertSessionHasErrors(['login']);

        $this->assertGuest();
    }

    public function test_the_audit_row_captures_the_forwarded_client_ip(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(
            ['email' => 'owner@clinic.test', 'password' => self::PASSWORD],
            ['X-Forwarded-For' => '203.0.113.9, 10.0.0.1'],
        );

        $this->assertSame('203.0.113.9', AuditLog::where('action', 'LOGIN_SUCCESS')->sole()->ip);
    }

    public function test_x_real_ip_is_used_when_forwarded_for_is_absent(): void
    {
        $this->createUser(['email' => 'owner@clinic.test']);

        $this->postLogin(
            ['email' => 'owner@clinic.test', 'password' => self::PASSWORD],
            ['X-Real-IP' => '198.51.100.4'],
        );

        $this->assertSame('198.51.100.4', AuditLog::where('action', 'LOGIN_SUCCESS')->sole()->ip);
    }

    // -------------------------------------------------------------- hashing

    public function test_the_stored_password_is_an_argon2id_hash(): void
    {
        $user = $this->createUser(['email' => 'owner@clinic.test']);

        $this->assertStringStartsWith('$argon2id$', $user->password);
    }

    public function test_the_login_password_check_uses_the_stored_hash(): void
    {
        $hash = Hash::make(self::PASSWORD);
        $user = $this->createUser(['email' => 'owner@clinic.test', 'password' => $hash]);

        $this->postLogin(['email' => 'owner@clinic.test', 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user->fresh());
    }
}
