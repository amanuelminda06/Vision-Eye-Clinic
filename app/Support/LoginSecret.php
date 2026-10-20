<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The rule for the secret staff-login path (LOGIN_SECRET_PATH).
 *
 * The staff login lives at `/login/<LOGIN_SECRET_PATH>`; every other
 * `/login/...` path is a real 404. The value must be at least 8 characters
 * of `[A-Za-z0-9_-]` and is never echoed anywhere.
 *
 * When must it be refused?
 *
 * - A MALFORMED value (not empty, but too short or with illegal characters)
 *   is always refused — a typo must never reach production.
 * - An EMPTY value is refused only when the app is SERVING in production.
 *   The install (`composer install` → `artisan package:discover`) and every
 *   console command boot the app BEFORE the `.env` exists, so refusing an
 *   empty value there would make the deployment impossible. In the console
 *   an empty value simply means "no login configured yet" — the staff login
 *   stays unreachable, and the go-live checklist covers it.
 */
final class LoginSecret
{
    public const ERROR = 'LOGIN_SECRET_PATH must be set to at least 8 characters matching [A-Za-z0-9_-].';

    /** True when the value may be used as the staff login path. */
    public static function isValid(?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false;
        }

        return strlen($secret) >= 8
            && preg_match('/^[A-Za-z0-9_-]+$/', $secret) === 1;
    }

    /**
     * Throws when the value must not be accepted.
     *
     * @param  bool  $serving  true when the app answers a web request
     * @param  bool  $production  true when APP_ENV is production
     */
    public static function assertUsable(?string $secret, bool $serving, bool $production): void
    {
        if (self::isValid($secret)) {
            return;
        }

        $malformed = $secret !== null && $secret !== '';

        if ($malformed || ($serving && $production)) {
            throw new RuntimeException(self::ERROR);
        }
    }
}
