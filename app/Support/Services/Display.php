<?php

declare(strict_types=1);

namespace App\Support\Services;

/**
 * Pure, locale-aware display helpers for service names and receipt labels,
 * ported from src/modules/services/display.ts.
 */
final class Display
{
    /**
     * Full display name in the given locale (English default).
     *
     * @param  array{name: string, nameEn?: string|null, nameAm?: string|null}  $source
     */
    public static function serviceNameIn(string $locale, array $source): string
    {
        $nameAm = $source['nameAm'] ?? null;
        $nameEn = $source['nameEn'] ?? null;

        if ($locale === 'am') {
            return $nameAm ?? $nameEn ?? $source['name'];
        }

        return $nameEn ?? $source['name'];
    }

    /**
     * Short receipt label for the given locale. Falls back to the other
     * language, then to `fallback` (the full service name captured at sale
     * time).
     *
     * @param  array{receiptLabelEn?: string|null, receiptLabelAm?: string|null, fallback: string}  $source
     */
    public static function receiptLabelIn(string $locale, array $source): string
    {
        $labelEn = $source['receiptLabelEn'] ?? null;
        $labelAm = $source['receiptLabelAm'] ?? null;

        if ($locale === 'am') {
            return $labelAm ?? $labelEn ?? $source['fallback'];
        }

        return $labelEn ?? $labelAm ?? $source['fallback'];
    }
}
