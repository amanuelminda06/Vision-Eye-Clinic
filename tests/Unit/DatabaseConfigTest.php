<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DatabaseConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule for the database credentials (DB_USERNAME / DB_PASSWORD).
 *
 * Empty credentials are refused while the app is SERVING (so the operator
 * reads "DB_USERNAME and DB_PASSWORD are empty" instead of a PDO "Access
 * denied for user ''"), but never in the console — the install runs before
 * the .env exists.
 */
class DatabaseConfigTest extends TestCase
{
    public function test_a_complete_pair_is_accepted(): void
    {
        $this->assertFalse(DatabaseConfig::isMissing('vision_app', 'secret'));
        $this->assertFalse(DatabaseConfig::isMissing('root', 'x'));

        DatabaseConfig::assertUsable('mysql', 'vision_app', 'secret', true);
        DatabaseConfig::assertUsable('mysql', 'vision_app', 'secret', false);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('missingPairs')]
    public function test_an_incomplete_pair_is_detected(?string $user, ?string $password): void
    {
        $this->assertTrue(DatabaseConfig::isMissing($user, $password));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function missingPairs(): array
    {
        return [
            'both empty' => ['', ''],
            'no user' => [null, 'secret'],
            'no password' => ['vision_app', null],
            'empty password' => ['vision_app', ''],
            'empty user' => ['', 'secret'],
        ];
    }

    public function test_only_a_real_database_connection_needs_credentials(): void
    {
        $this->assertTrue(DatabaseConfig::needsCredentials('mysql'));
        $this->assertTrue(DatabaseConfig::needsCredentials('mariadb'));
        $this->assertFalse(DatabaseConfig::needsCredentials('sqlite'));
        $this->assertFalse(DatabaseConfig::needsCredentials(null));
        $this->assertFalse(DatabaseConfig::needsCredentials(''));
    }

    public function test_sqlite_never_needs_credentials(): void
    {
        DatabaseConfig::assertUsable('sqlite', null, null, true);
        DatabaseConfig::assertUsable('sqlite', '', '', true);

        $this->addToAssertionCount(1);
    }

    public function test_empty_credentials_are_refused_while_serving(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(DatabaseConfig::ERROR);

        DatabaseConfig::assertUsable('mysql', '', '', true);
    }

    public function test_empty_credentials_are_allowed_in_the_console(): void
    {
        // The install (composer install → artisan package:discover) runs
        // before the .env exists.
        DatabaseConfig::assertUsable('mysql', '', '', false);
        DatabaseConfig::assertUsable('mysql', null, null, false);

        $this->addToAssertionCount(1);
    }
}
