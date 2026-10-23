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

    /**
     * Patient ID photos are OFF by default — the clinic opts in from the
     * settings screen. When off the upload, the capture and every view of a
     * stored photo are refused (the UI hides them too).
     */
    public static function patientIdPhotosEnabled(): bool
    {
        return self::get('patient_id_photos_enabled', false) === true;
    }

    /**
     * Patient sick leave is OFF by default — the clinic opts in from the
     * settings screen. When off the whole tab and every write are refused.
     */
    public static function patientSickLeaveEnabled(): bool
    {
        return self::get('patient_sick_leave_enabled', false) === true;
    }

    /**
     * Auto-approve appointment requests — when ON, public bookings create
     * confirmed appointments directly instead of filing requests for the
     * front desk to convert.
     */
    public static function autoApproveAppointmentRequests(): bool
    {
        return self::get('auto_approve_appointment_requests', false) === true;
    }
}
