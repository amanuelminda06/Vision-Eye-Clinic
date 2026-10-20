<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LoginPolicy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Port of src/lib/login-policy.test.ts + src/lib/ratelimit.test.ts (the
 * legacy gold standards): the 5-failure / 15-minute lockout maths, success
 * reset, and the 30-per-5-minute sliding window rate limiter semantics.
 */
class LoginPolicyTest extends TestCase
{
    private const NOW = '2026-10-08T10:00:00Z';

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    public function test_locks_the_account_on_the_fifth_consecutive_failure_for_15_minutes(): void
    {
        $state = ['failed_login_count' => 0, 'locked_until' => null];

        for ($attempt = 1; $attempt <= LoginPolicy::MAX_FAILURES; $attempt++) {
            $state = LoginPolicy::stateAfterFailure($state['failed_login_count'], self::now());
        }

        $this->assertSame(5, $state['failed_login_count']);
        $this->assertNotNull($state['locked_until']);
        $this->assertSame('2026-10-08 10:15:00', $state['locked_until']);
    }

    public function test_does_not_lock_before_the_fifth_failure(): void
    {
        for ($failures = 1; $failures < LoginPolicy::MAX_FAILURES; $failures++) {
            $state = LoginPolicy::stateAfterFailure($failures - 1, self::now());
            $this->assertNull($state['locked_until']);
            $this->assertSame($failures, $state['failed_login_count']);
        }
    }

    public function test_is_locked_is_true_only_inside_the_lock_window(): void
    {
        $lockedUntil = self::now()->modify('+'.LoginPolicy::LOCK_MINUTES.' minutes');

        $this->assertTrue(LoginPolicy::isLocked($lockedUntil, self::now()));
        $this->assertTrue(LoginPolicy::isLocked($lockedUntil->format('Y-m-d H:i:s'), self::now()));
        // Strictly after the stamp the lock is over (legacy uses >).
        $this->assertFalse(LoginPolicy::isLocked($lockedUntil, $lockedUntil->modify('+1 second')));
        $this->assertFalse(LoginPolicy::isLocked($lockedUntil, $lockedUntil));
        $this->assertFalse(LoginPolicy::isLocked(null, self::now()));
        $this->assertFalse(LoginPolicy::isLocked('', self::now()));
    }

    public function test_a_successful_login_resets_the_counter_and_clears_the_lock(): void
    {
        $state = LoginPolicy::stateAfterSuccess(self::now());

        $this->assertSame(0, $state['failed_login_count']);
        $this->assertNull($state['locked_until']);
        $this->assertSame('2026-10-08 10:00:00', $state['last_login_at']);
        $this->assertFalse(LoginPolicy::isLocked($state['locked_until'], self::now()));
    }

    public function test_rate_limit_allows_up_to_the_default_limit_then_blocks(): void
    {
        for ($i = 0; $i < LoginPolicy::RATE_LIMIT_MAX; $i++) {
            $this->assertTrue(LoginPolicy::checkRateLimit('ip-a'), "attempt {$i} should pass");
        }

        $this->assertFalse(LoginPolicy::checkRateLimit('ip-a'));
        // A different key is unaffected.
        $this->assertTrue(LoginPolicy::checkRateLimit('ip-b'));
    }

    public function test_rate_limit_supports_custom_limits_and_windows(): void
    {
        $this->assertTrue(LoginPolicy::checkRateLimit('k', 2, 1));
        $this->assertTrue(LoginPolicy::checkRateLimit('k', 2, 1));
        $this->assertFalse(LoginPolicy::checkRateLimit('k', 2, 1));
    }

    public function test_rate_limit_resets_a_bucket_after_its_window_expires(): void
    {
        $key = 'login_rate:'.hash('sha256', 'k');

        // Live bucket at the cap: blocked.
        Cache::put($key, ['count' => 30, 'reset_at' => time() + 60], 120);
        $this->assertFalse(LoginPolicy::checkRateLimit('k'));

        // Expired bucket: allowed again (fresh count).
        Cache::put($key, ['count' => 30, 'reset_at' => time()], 120);
        $this->assertTrue(LoginPolicy::checkRateLimit('k'));
        // …and the fresh bucket now counts from 1 (2nd call still passes).
        $this->assertTrue(LoginPolicy::checkRateLimit('k'));
    }

    public function test_reset_rate_limits_clears_all_buckets(): void
    {
        $key = 'login_rate:'.hash('sha256', 'r');
        Cache::put($key, ['count' => 30, 'reset_at' => time() + 60], 120);
        $this->assertFalse(LoginPolicy::checkRateLimit('r'));

        LoginPolicy::resetRateLimits();

        $this->assertTrue(LoginPolicy::checkRateLimit('r'));
    }

    public function test_null_ip_is_keyed_as_unknown(): void
    {
        // Feature tests run without proxy headers → clientIp() is null; the
        // bucket must still be stable and hash the literal 'unknown'.
        $expected = 'login_rate:'.hash('sha256', 'unknown');
        $this->assertTrue(LoginPolicy::checkRateLimit(null));
        $this->assertTrue(Cache::has($expected));
    }
}
