<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The rule for the database credentials (DB_USERNAME / DB_PASSWORD).
 *
 * The app reads them from the `.env`; `.env.example` ships them EMPTY on
 * purpose, because the values are the ones Plesk generates for the site's
 * MySQL user and nobody else knows them.
 *
 * When must they be refused?
 *
 * - While the app is SERVING, an empty user or password is a hard error: the
 *   alternative is a PDO "Access denied for user ''" buried in the log, with
 *   no hint about what is actually wrong.
 * - In the console (the install, `artisan migrate`, …) they are not
 *   refused: the install runs before the `.env` exists, and the commands
 *   that need the database fail with the database's own (clear) error.
 *
 * SQLite is exempt — it has no credentials.
 */
final class DatabaseConfig
{
    public const ERROR = 'DB_USERNAME and DB_PASSWORD are empty in .env — the app cannot connect to the database.';

    /** True when the connection needs a user and a password. */
    public static function needsCredentials(?string $connection): bool
    {
        return $connection !== null && $connection !== '' && $connection !== 'sqlite';
    }

    /** True when either the user or the password is missing. */
    public static function isMissing(?string $username, ?string $password): bool
    {
        return ($username === null || $username === '')
            || ($password === null || $password === '');
    }

    /**
     * Throws when the app is serving without usable credentials.
     */
    public static function assertUsable(
        ?string $connection,
        ?string $username,
        ?string $password,
        bool $serving,
    ): void {
        if (! self::needsCredentials($connection)) {
            return;
        }

        if (! self::isMissing($username, $password)) {
            return;
        }

        if ($serving) {
            throw new RuntimeException(self::ERROR);
        }
    }
}
