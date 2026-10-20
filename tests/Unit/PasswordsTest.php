<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Passwords;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/passwords.test.ts (legacy gold standard): the exact rule
 * order (length → common → personal) and the i18n KEYS it returns, plus the
 * 14-char unambiguous temporary password generator.
 */
class PasswordsTest extends TestCase
{
    private const TEMP_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function test_rejects_passwords_shorter_than_the_minimum_length(): void
    {
        $this->assertSame('passwordTooShort', Passwords::passwordError('short1'));
        $this->assertSame('passwordTooShort', Passwords::passwordError(''));
        $this->assertSame('passwordTooShort', Passwords::passwordError(str_repeat('a', 9)));
        $this->assertNull(Passwords::passwordError(str_repeat('a', 10)));
    }

    public function test_rejects_common_passwords(): void
    {
        $this->assertSame('passwordCommon', Passwords::passwordError('password123'));
        $this->assertSame('passwordCommon', Passwords::passwordError('Password123'));
        $this->assertSame('passwordCommon', Passwords::passwordError('1234567890'));
        $this->assertSame('passwordCommon', Passwords::passwordError('qwertyuiop'));
    }

    public function test_rejects_passwords_containing_the_email_local_part(): void
    {
        $this->assertSame('passwordPersonal', Passwords::passwordError(
            'abebeKebede2026!',
            ['email' => 'abebekebede@clinic.et'],
        ));
        // Local part shorter than 4 chars is ignored; unrelated value passes.
        $this->assertNull(Passwords::passwordError(
            'clinic-admin-75',
            ['email' => 'owner@clinic.et'],
        ));
    }

    public function test_rejects_passwords_containing_the_user_name(): void
    {
        $this->assertSame('passwordPersonal', Passwords::passwordError(
            'hi I am Mulugeta 77',
            ['name' => 'Mulugeta'],
        ));
    }

    public function test_accepts_a_strong_unrelated_password(): void
    {
        $this->assertNull(Passwords::passwordError('R7#kQp2!vLmW', ['email' => 'a@b.et', 'name' => 'x']));
        $this->assertNull(Passwords::passwordError('correct horse battery staple!!'));
    }

    public function test_is_valid_and_error_convenience_wrappers(): void
    {
        $this->assertTrue(Passwords::isValid('correct horse battery staple!!'));
        $this->assertFalse(Passwords::isValid('password123'));
        $this->assertSame('passwordTooShort', Passwords::error('short'));
    }

    public function test_generate_temporary_password_length_and_alphabet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $pwd = Passwords::generateTemporary();
            $this->assertSame(14, strlen($pwd));
            $this->assertMatchesRegularExpression('/^['.preg_quote(self::TEMP_ALPHABET, '/').']{14}$/', $pwd);
        }
    }

    public function test_generate_temporary_password_is_random_across_calls(): void
    {
        $this->assertNotSame(Passwords::generateTemporary(), Passwords::generateTemporary());
    }
}
