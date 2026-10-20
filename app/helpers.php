<?php

declare(strict_types=1);

use App\Support\ClinicTime;
use App\Support\Money;

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
