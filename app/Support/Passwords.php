<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Password rules, ported from src/lib/passwords.ts.
 * Min 10 chars, reject common passwords (exact match), reject passwords that
 * contain personal info (email local part or name, case-insensitive, >= 4 chars).
 * Returns i18n KEYS under `validation.*` — never UI strings.
 */
final class Passwords
{
    public const MIN_PASSWORD_LENGTH = 10;

    /** Password helper module loaded. */
    public const PASSWORD_MODULE_LOADED = true;

    /**
     * Exact legacy COMMON_PASSWORDS set (lowercased entries; compared as a whole).
     *
     * @var list<string>
     */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', 'p@ssw0rd', 'p@ssword', 'passw0rd',
        '123456', '12345678', '123456789', '1234567890', '12345678910',
        '111111', '123123', '1234567', '12345', '1234',
        '654321', '666666', '999999', '000000',
        'qwerty', 'qwertyuiop', 'qwerty123', 'qazwsx', 'qazwsxedc', 'zaq12wsx', '1qaz2wsx',
        'abc123', 'abcd1234', 'iloveyou', 'monkey', 'dragon', 'letmein', 'welcome', 'welcome1',
        'admin', 'admin123', 'administrator', 'root', 'toor', 'login', 'master', 'shadow',
        'sunshine', 'princess', 'football', 'baseball', 'soccer', 'hockey',
        'superman', 'batman', 'spiderman', 'starwars', 'trustno1', 'mustang',
        'michael', 'jennifer', 'jordan23', 'charlie', 'thomas', 'robert', 'daniel',
        'hunter', 'ranger', 'buster', 'summer', 'winter', 'autumn', 'spring',
        'changeme', 'secret', 'temp123', 'test1234', 'testing123', 'default',
        'pass1234', 'letmein123', 'iloveyou1', 'football123',
        'google', 'facebook', 'amazon', 'oracle', 'mysql', 'postgres', 'sqlserver',
        'computer', 'internet', 'samsung', 'iphone', 'android',
        'nihao', 'woaini', 'iloveu', 'love1234', 'happy123', 'freedom', 'whatever',
        'nothing', 'nopass', 'nopassword', 'passwort', 'contraseña',
        'zxcvbnm', 'asdfghjkl', 'asdfgh', 'poiuytrewq', 'lkjhgfdsa',
    ];

    /**
     * Validates a candidate password. Returns an i18n key when invalid, null when acceptable.
     *
     * @param  array{email?: ?string, name?: ?string}  $opts
     */
    public static function passwordError(string $password, array $opts = []): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'passwordTooShort';
        }

        $lower = mb_strtolower($password);

        if (in_array($lower, self::COMMON_PASSWORDS, true)) {
            return 'passwordCommon';
        }

        $email = (string) ($opts['email'] ?? '');
        $localPart = mb_strtolower(explode('@', $email)[0] ?? '');
        if (mb_strlen($localPart) >= 4 && str_contains($lower, $localPart)) {
            return 'passwordPersonal';
        }

        $name = mb_strtolower(trim((string) ($opts['name'] ?? '')));
        if (mb_strlen($name) >= 4 && str_contains($lower, $name)) {
            return 'passwordPersonal';
        }

        return null;
    }

    /** Convenience: null when acceptable. */
    public static function error(string $password, array $opts = []): ?string
    {
        return self::passwordError($password, $opts);
    }

    public static function isValid(string $password, array $opts = []): bool
    {
        return self::passwordError($password, $opts) === null;
    }

    private const TEMP_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Cryptographically random temporary password (14 chars, no ambiguous glyphs). */
    public static function generateTemporary(): string
    {
        $alphabet = self::TEMP_ALPHABET;
        $max = strlen($alphabet);
        $password = '';

        for ($i = 0; $i < 14; $i++) {
            // Rejection sampling to avoid modulo bias (crypto-secure).
            $limit = intdiv(0x100000000, $max) * $max;
            do {
                $byte = random_int(0, 0xFFFFFFFF);
            } while ($byte >= $limit);
            $password .= $alphabet[$byte % $max];
        }

        return $password;
    }
}
