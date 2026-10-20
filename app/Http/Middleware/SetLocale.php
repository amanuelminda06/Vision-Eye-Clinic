<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale switcher: ?lang=en|am sets a cookie and switches the app locale.
 * English is the default; Amharic is complete parity.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('lang');

        if (is_string($locale) && in_array($locale, ['en', 'am'], true)) {
            App::setLocale($locale);

            $response = $next($request);

            $response->headers->setCookie(
                Cookie::create(self::COOKIE, $locale, now()->addYear()->getTimestamp(), '/')
            );

            return $response;
        }

        $cookie = $request->cookie(self::COOKIE);
        if (is_string($cookie) && in_array($cookie, ['en', 'am'], true)) {
            App::setLocale($cookie);
        }

        return $next($request);
    }
}
