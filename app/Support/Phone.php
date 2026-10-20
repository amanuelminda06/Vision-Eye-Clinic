<?php

declare(strict_types=1);

namespace App\Support;

/**
 * E.164 phone handling for Ethiopia, ported exactly from src/lib/phone.ts.
 *
 * Rules (identical to legacy):
 * - strips spaces, dashes, parentheses, dots.
 * - accepts 09XXXXXXXX / 07XXXXXXXX / 9XXXXXXXX / 7XXXXXXXX /
 *   +2519XXXXXXXX / +2517XXXXXXXX / 2519XXXXXXXX / 2517XXXXXXXX.
 * - everything else (short codes, 8xx, 00-prefix, mixed alpha) returns null.
 */
final class Phone
{
    /** Same shapes as legacy MOBILE_NATIONAL / MOBILE_SHORT / MOBILE_E164. */
    private const MOBILE_NATIONAL = '/^0[97]\d{8}$/';

    private const MOBILE_SHORT = '/^[97]\d{8}$/';

    private const MOBILE_E164 = '/^251[97]\d{8}$/';

    /**
     * Normalize a phone to E.164 (+2519... / +2517...) or null when it cannot
     * be trusted. (legacy normalizePhone)
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

        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);

            return preg_match(self::MOBILE_E164, $digits) === 1 ? '+'.$digits : null;
        }

        if (preg_match(self::MOBILE_NATIONAL, $cleaned) === 1) {
            return '+251'.substr($cleaned, 1); // 09/07XXXXXXXX → 9/7XXXXXXXX
        }

        if (preg_match(self::MOBILE_SHORT, $cleaned) === 1) {
            return '+251'.$cleaned;
        }

        if (preg_match(self::MOBILE_E164, $cleaned) === 1) {
            return '+'.$cleaned;
        }

        return null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::toE164($raw) !== null;
    }

    /**
     * Like toE164, but always explains WHY an input was rejected. (legacy parsePhone)
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

    /** Display helper: +251912345678 → 0912345678, anything else untouched. (legacy formatPhoneDisplay) */
    public static function display(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return $e164;
        }

        return str_starts_with($e164, '+251') ? '0'.substr($e164, 4) : $e164;
    }
}
