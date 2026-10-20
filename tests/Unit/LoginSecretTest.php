<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LoginSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule for LOGIN_SECRET_PATH (the secret staff-login path).
 *
 * A malformed value is always refused. An empty one is refused only when the
 * app is SERVING in production — the install (`composer install` →
 * `artisan package:discover`) and the console commands boot the app before
 * the .env exists, and must not be blocked by it.
 */
class LoginSecretTest extends TestCase
{
    public function test_a_valid_secret_is_accepted(): void
    {
        $this->assertTrue(LoginSecret::isValid('testsecret123'));
        $this->assertTrue(LoginSecret::isValid('a-b_c-1234'));

        LoginSecret::assertUsable('testsecret123', true, true);
        LoginSecret::assertUsable('testsecret123', false, true);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidSecrets')]
    public function test_an_invalid_secret_is_not_valid(?string $secret): void
    {
        $this->assertFalse(LoginSecret::isValid($secret));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function invalidSecrets(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['short7'],
            'a space' => ['secret path'],
            'a slash' => ['secret/path'],
            'a dot' => ['secret.path'],
            'non ascii' => ['ስክሪት123'],
        ];
    }

    public function test_a_malformed_secret_is_refused_everywhere(): void
    {
        // Console (the install) and web alike — a typo must never be accepted.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(LoginSecret::ERROR);

        LoginSecret::assertUsable('short', false, true);
    }

    public function test_an_empty_secret_is_refused_when_serving_in_production(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(LoginSecret::ERROR);

        LoginSecret::assertUsable(null, true, true);
    }

    public function test_an_empty_secret_is_allowed_in_the_console(): void
    {
        // The install runs `artisan package:discover` before the .env exists.
        LoginSecret::assertUsable(null, false, true);
        LoginSecret::assertUsable('', false, false);

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_secret_is_allowed_when_serving_outside_production(): void
    {
        // A development install: no login, but the site still answers.
        LoginSecret::assertUsable(null, true, false);

        $this->addToAssertionCount(1);
    }
}
