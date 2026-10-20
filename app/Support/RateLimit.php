<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiter;

/**
 * Fixed-window rate limiter, ported from src/lib/ratelimit.ts.
 *
 * The legacy implementation kept the buckets in a process-local map, which
 * does not survive PHP-FPM request boundaries — the counters would reset on
 * every request and the limit would never trigger. This port uses Laravel's
 * cache-backed limiter (file/database store by default), so the window is
 * genuinely enforced across requests.
 */
final class RateLimit
{
    public const RATE_LIMIT_MAX = 30;

    public const RATE_LIMIT_WINDOW_MS = 5 * 60_000;

    /** @var list<string> Keys seen in this process (for the test reset helper). */
    private static array $keys = [];

    /**
     * Returns true when the attempt is allowed (and counts it), false when
     * blocked.
     */
    public static function check(string $key, ?int $max = null, ?int $windowMs = null): bool
    {
        $max ??= self::RATE_LIMIT_MAX;
        $windowMs ??= self::RATE_LIMIT_WINDOW_MS;
        $decay = max(1, (int) ceil($windowMs / 1000));

        $limiter = app(RateLimiter::class);
        $signature = hash('sha256', $key);

        self::$keys[] = $key;

        if ($limiter->tooManyAttempts($signature, $max)) {
            return false;
        }

        $limiter->hit($signature, $decay);

        return true;
    }

    /** Seconds until the window resets (0 when the key is unknown). */
    public static function availableIn(string $key): int
    {
        $limiter = app(RateLimiter::class);

        return $limiter->availableIn(hash('sha256', $key));
    }

    /** Test helper — clears the bucket for one key. */
    public static function reset(?string $key = null): void
    {
        $limiter = app(RateLimiter::class);

        if ($key !== null) {
            $limiter->clear(hash('sha256', $key));

            return;
        }

        foreach (self::$keys as $tracked) {
            $limiter->clear(hash('sha256', $tracked));
        }

        self::$keys = [];
    }
}
