<?php

declare(strict_types=1);

namespace App\Support\Services;

/**
 * Fixed service categories, ported from src/modules/services/categories.ts.
 *
 * Stored as stable keys on `services.category` (a string column, so new keys
 * never need a migration) and rendered with the `services.cat*` translations.
 */
final class Categories
{
    /** @var list<string> */
    public const SERVICE_CATEGORIES = [
        'consultation',
        'refraction',
        'diagnostics',
        'tests',
        'glasses',
        'optical',
        'contact_lenses',
        'specialist',
        'surgery',
        'other',
    ];

    /** `__('services.<key>')` for each category. */
    public const CATEGORY_LABEL_KEYS = [
        'consultation' => 'catConsultation',
        'refraction' => 'catRefraction',
        'diagnostics' => 'catDiagnostics',
        'tests' => 'catTests',
        'glasses' => 'catGlasses',
        'optical' => 'catOptical',
        'contact_lenses' => 'catContactLenses',
        'specialist' => 'catSpecialist',
        'surgery' => 'catSurgery',
        'other' => 'catOther',
    ];

    public static function isServiceCategory(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::SERVICE_CATEGORIES, true);
    }

    /** The label key for a category, or the raw key when unknown (legacy UI). */
    public static function labelKey(string $category): string
    {
        return self::CATEGORY_LABEL_KEYS[$category] ?? $category;
    }
}
