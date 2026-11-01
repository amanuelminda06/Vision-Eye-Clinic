<?php

declare(strict_types=1);

namespace App\Support;

final class Phone
{
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
            return $cleaned;
        }

        return str_starts_with($cleaned, '0')
            ? '+251'.substr($cleaned, 1)
            : '+'.$cleaned;
    }

    public static function isValid(?string $raw): bool
    {
        return self::toE164($raw) !== null;
    }

    public static function parse(?string $raw): array
    {
        $value = self::toE164($raw);

        return $value === null
            ? ['ok' => false, 'error' => 'invalidPhone']
            : ['ok' => true, 'value' => $value];
    }

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

    public static function display(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return $e164;
        }

        return str_starts_with($e164, '+251') ? '0'.substr($e164, 4) : $e164;
    }
}
