<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Typed access to the key/value clinic settings, ported from
 * src/modules/settings/queries.ts + src/lib/settings.ts.
 *
 * `map()` returns every key → decoded value (the `Setting` model casts the
 * JSON column). `defaultLanguage()` and `timezone()`/`currency()` are the
 * helpers the rest of the app uses; the raw map stays available for screens
 * that render the full settings form (slice 8).
 */
final class Settings
{
    /** All settings as a key → value map (values are decoded from JSON). */
    public static function map(): array
    {
        return Setting::query()->pluck('value', 'key')->all();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::find($key);

        return $setting === null ? $default : $setting->value;
    }

    /** Effective outbound-campaign default language: only 'am' flips it off 'en'. */
    public static function defaultLanguage(): string
    {
        return self::get('default_language') === 'am' ? 'am' : 'en';
    }

    /** Clinic display timezone (falls back to the Africa/Addis_Ababa default). */
    public static function timezone(): string
    {
        $value = self::get('timezone');

        return is_string($value) && $value !== '' ? $value : 'Africa/Addis_Ababa';
    }

    public static function currency(): string
    {
        $value = self::get('currency');

        return is_string($value) && $value !== '' ? $value : 'ETB';
    }
}
