<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Login policy, ported from src/lib/login-policy.ts + src/lib/ratelimit.ts.
 *
 * - Account lockout lives on the users row: 5 failed attempts within the
 *   window lock the account for 15 minutes; success resets the counter.
 * - Independent per-IP rate limit: 30 login attempts / 5 minutes (cache-based,
 *   single-instance deployment like the legacy process-local limiter).
 */
final class LoginPolicy
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const RATE_LIMIT_MAX = 30;

    public const RATE_LIMIT_WINDOW_MINUTES = 5;

    /** Pure: is the account locked at `now`? (legacy isLocked) */
    public static function isLocked(string|\DateTimeInterface|null $lockedUntil, ?\DateTimeInterface $now = null): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $until = $lockedUntil instanceof \DateTimeInterface
            ? $lockedUntil
            : new \DateTimeImmutable($lockedUntil, new \DateTimeZone('UTC'));

        return $until->getTimestamp() > $now->getTimestamp();
    }

    /**
     * Pure: state to persist after a failed password attempt.
     * The 5th failure sets lockedUntil = now + 15 minutes.
     *
     * @return array{failed_login_count: int, locked_until: ?string}
     */
    public static function stateAfterFailure(int $failedCount, \DateTimeInterface $now): array
    {
        $count = $failedCount + 1;
        $lockedUntil = $count >= self::MAX_FAILURES
            ? (new \DateTimeImmutable($now->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')))
                ->modify('+'.self::LOCK_MINUTES.' minutes')
                ->format('Y-m-d H:i:s')
            : null;

        return ['failed_login_count' => $count, 'locked_until' => $lockedUntil];
    }

    /** Pure: state to persist after a successful login. */
    public static function stateAfterSuccess(\DateTimeInterface $now): array
    {
        return [
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => (new \DateTimeImmutable($now->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];
    }

    /** Apply a failure to the stored user row (no-op when already locked). */
    public static function recordFailure(User $user): void
    {
        if (self::isLocked($user->locked_until)) {
            return;
        }

        $state = self::stateAfterFailure((int) $user->failed_login_count, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $user->forceFill($state)->save();
    }

    /** Reset counters after a successful login. */
    public static function recordSuccess(User $user): void
    {
        $user->forceFill(self::stateAfterSuccess(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))->save();
    }

    /**
     * Per-IP login rate limit (30 attempts / 5 min). Returns false when blocked.
     * Keyed exactly like legacy: `login:<ip>`.
     */
    public static function checkRateLimit(?string $ip, int $max = self::RATE_LIMIT_MAX, int $windowMinutes = self::RATE_LIMIT_WINDOW_MINUTES): bool
    {
        $key = 'login_rate:'.hash('sha256', $ip ?? 'unknown');
        $now = time();
        $bucket = Cache::get($key);

        if (! is_array($bucket) || (int) ($bucket['reset_at'] ?? 0) <= $now) {
            Cache::put($key, ['count' => 1, 'reset_at' => $now + $windowMinutes * 60], $windowMinutes * 60);

            return true;
        }

        if ((int) $bucket['count'] >= $max) {
            return false;
        }

        $bucket['count']++;
        Cache::put($key, $bucket, $windowMinutes * 60);

        return true;
    }

    /** Test helper: clears rate-limit buckets. */
    public static function resetRateLimits(): void
    {
        Cache::flush();
    }
}
