<?php

declare(strict_types=1);

namespace App\Support;

/**
 * E.164 phone handling.
 *
 * Rules:
 * - strips spaces, dashes, parentheses, dots.
 * - accepts any real phone number, not just Ethiopian mobiles: local
 *   0-prefixed numbers (mobile 09/07, landline 011…), Ethiopian numbers in
 *   country-code form (2519…), international +numbers and the 00 prefix.
 * - rejects anything that is not digits (letters, short codes, empty input).
 * - Ethiopian numbers are always normalized to +251 followed by nine digits;
 *   other international numbers keep their country code.
 */
final class Phone
{
    /** E.164 allows at most 15 digits; below 7 digits is a short code. */
    private const MIN_DIGITS = 7;

    private const MAX_DIGITS = 15;

    /** Ethiopian numbers are +251 followed by exactly nine digits. */
    private const ETHIOPIA_DIGITS = 12;

    /**
     * Normalize a phone to E.164 or null when it cannot be trusted.
     */
    public static function toE164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
        if ($cleaned === '') {
            return null;
        }

        // Anything that is not digits with an optional leading + is not a phone.
        if (preg_match('/^\+?\d+$/', $cleaned) !== 1) {
            return null;
        }

        $digits = ltrim($cleaned, '+');

        // 00 is the international access prefix used in many countries.
        if (str_starts_with($digits, '00')) {
            return self::international(substr($digits, 2));
        }

        // Already in international form.
        if (str_starts_with($cleaned, '+')) {
            return self::international($digits);
        }

        // Ethiopian local form: 0 + nine digits (mobile 09/07 or landline 011…).
        if (preg_match('/^0\d{9}$/', $digits) === 1) {
            return self::ethiopian(substr($digits, 1));
        }

        // A stray 0-prefixed value that is not a full local number is junk.
        if (str_starts_with($digits, '0')) {
            return null;
        }

        // Ethiopian country code without the plus (251 + nine digits).
        if (preg_match('/^251\d{9}$/', $digits) === 1) {
            return '+'.$digits;
        }

        // Legacy short mobile form (9XXXXXXXX / 7XXXXXXXX).
        if (preg_match('/^[97]\d{8}$/', $digits) === 1) {
            return self::ethiopian($digits);
        }

        return self::international($digits);
    }

    public static function isValid(?string $raw): bool
    {
        return self::toE164($raw) !== null;
    }

    /**
     * Like toE164, but always explains WHY an input was rejected.
     *
     * @return array{ok: true, value: string}|array{ok: false, error: 'required'|'invalidPhone'}
     */
    public static function parse(?string $input): array
    {
        if ($input === null || trim($input) === '') {
            return ['ok' => false, 'error' => 'required'];
        }

        $value = self::toE164($input);

        return $value === null
            ? ['ok' => false, 'error' => 'invalidPhone']
            : ['ok' => true, 'value' => $value];
    }

    /**
     * Search fragment candidates for phone-based lookup: the raw digits, plus
     * E.164/national expansions when the fragment starts with 09/07/2519/2517.
     * No digits → null (caller skips the phone clause). (legacy phoneSearchVariants)
     *
     * @return list<string>|null
     */
    public static function phoneSearchVariants(?string $fragment): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $fragment) ?? '';
        if ($digits === '') {
            return null;
        }

        $variants = [$digits];

        if (str_starts_with($digits, '09') || str_starts_with($digits, '07')) {
            $variants[] = '+251'.substr($digits, 1);
            $variants[] = substr($digits, 1);
        }

        if (str_starts_with($digits, '2519') || str_starts_with($digits, '2517')) {
            $variants[] = '+'.$digits;
        }

        return array_values(array_unique($variants));
    }

    /** Display helper: +251912345678 → 0912345678, anything else untouched. */
    public static function display(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return $e164;
        }

        return str_starts_with($e164, '+251') ? '0'.substr($e164, 4) : $e164;
    }

    /** +251 + nine national digits. */
    private static function ethiopian(string $national): ?string
    {
        return strlen($national) === 9 ? '+251'.$national : null;
    }

    /** A country-code number (no + yet); Ethiopian numbers must be exact. */
    private static function international(string $digits): ?string
    {
        if (str_starts_with($digits, '251') && strlen($digits) !== self::ETHIOPIA_DIGITS) {
            return null;
        }

        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS
            ? '+'.$digits
            : null;
    }
}
