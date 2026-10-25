<?php

declare(strict_types=1);

use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Storage;

/*
|--------------------------------------------------------------------------
| Clinic helpers
|--------------------------------------------------------------------------
|
| The translation catalogs came from next-intl, so placeholders use the
| {name} style rather than Laravel's :name. Use __t() whenever a message
| has placeholders; plain __() everywhere else.
|
*/

if (! function_exists('__t')) {
    /**
     * Translate with {placeholder} substitution (legacy next-intl style).
     *
     * @param  array<string, scalar|null>  $replace
     */
    function __t(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = __($key, [], $locale);

        foreach ($replace as $placeholder => $value) {
            $line = str_replace('{'.$placeholder.'}', (string) ($value ?? ''), $line);
        }

        return $line;
    }
}

if (! function_exists('field_error')) {
    /**
     * Translate a stored error KEY for display.
     *
     * Legacy split the two sources explicitly: field-level issues live under
     * `validation.*` (rendered by PatientForm's `tv()` helper) while global
     * action failures live under `common.*` (rendered by ActionError). Our
     * controllers store the bare key in the error bag, so this resolves the
     * validation catalog first and falls back to `common.*` for the shared
     * global keys (noFile, phoneTaken, unknownError, …). Unknown keys render
     * as-is rather than printing a namespace prefix.
     */
    function field_error(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $validation = "validation.{$key}";
        if (trans()->has($validation)) {
            $result = __($validation);

            return is_string($result) ? $result : (string) ($result[''] ?? $key);
        }

        $common = "common.{$key}";
        if (trans()->has($common)) {
            $result = __($common);

            return is_string($result) ? $result : (string) ($result[''] ?? $key);
        }

        return $key;
    }
}

if (! function_exists('login_path')) {
    /** The ONLY login URL: /login/<LOGIN_SECRET_PATH> (legacy loginPath). */
    function login_path(): string
    {
        return '/login/'.(string) config('auth.login_secret_path');
    }
}

if (! function_exists('clinic_now')) {
    /** Current time in the clinic display timezone (Africa/Addis_Ababa). */
    function clinic_now(): DateTimeImmutable
    {
        return ClinicTime::now();
    }
}

if (! function_exists('format_money')) {
    /**
     * Money display with the clinic currency code — legacy formatMoney:
     * format_money(1234.5) → "ETB 1,234.50". Pass the settings currency as
     * the second argument ("formatMoney(value, settings currency)" per spec).
     * Exact decimal throughout — no float ever touches the value.
     */
    function format_money(int|float|string $amount, string $currency = 'ETB'): string
    {
        return Money::formatMoney($amount, $currency);
    }
}

if (! function_exists('stored_photo_url')) {
    /**
     * The URL an <img> can load for a stored photo inside the signed-in ERP
     * (a staff photo, a patient ID photo).
     *
     * An uploaded blob goes through a login-gated route, so the bytes are
     * never exposed directly — the raw `/uploads/…` value must never be
     * rendered as an <img src> on its own. A staff photo is read through
     * `/staff-photos/{key}` (every signed-in role may read it); a patient ID
     * photo through `/uploads/{key}` (OWNER+RECEPTION only). A site-relative
     * path (`/images/doctors/x.png`) becomes an absolute asset() URL, so it
     * follows APP_URL and the forced https scheme on the Plesk host. An
     * absolute URL is content the clinic configured and is used unchanged.
     */
    function stored_photo_url(?string $photoUrl): ?string
    {
        if ($photoUrl === null || trim($photoUrl) === '') {
            return null;
        }

        $key = Storage::keyFromUrl($photoUrl);

        if ($key !== null) {
            // A staff photo is served by the route every signed-in role may
            // read; anything else (a patient ID photo) stays behind the
            // OWNER+RECEPTION upload route.
            if (str_starts_with($key, Storage::STAFF_PHOTO_KEY_PREFIX)) {
                return route('staff.photo', ['key' => $key]);
            }

            return route('uploads.show', ['key' => $key]);
        }

        if (str_starts_with($photoUrl, '/')) {
            return asset(ltrim($photoUrl, '/'));
        }

        return $photoUrl;
    }
}

if (! function_exists('public_asset_exists')) {
    /**
     * True when a file under public/ is really there.
     *
     * The public templates must never render a broken image box: an asset
     * that is not in the repository (or that a deployment did not carry over)
     * falls back to a placeholder or the person's initials instead.
     */
    function public_asset_exists(string $path): bool
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        return is_file(public_path($path));
    }
}

if (! function_exists('public_asset_url')) {
    /**
     * The URL of a public asset, or null when the file is missing.
     *
     * The URL is built with asset(), so it follows APP_URL and the forced
     * https scheme: on the Plesk host (behind the nginx proxy) the pages
     * must link to https://visioneye.clinic.et/… and never to http:// —
     * an http:// asset on an https page is blocked as mixed content and the
     * stylesheet, the logo or the hero image silently disappears.
     *
     * A missing file returns null, so the template can render a placeholder
     * instead of a broken image box.
     */
    function public_asset_url(string $path): ?string
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        if (! public_asset_exists($path)) {
            return null;
        }

        return asset($path);
    }
}
