<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustProxies;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: security headers (next.config.ts) + locale cookie (?lang=).
        $middleware->append(SecurityHeaders::class);
        $middleware->append(SetLocale::class);

        // The reverse proxy in front of Apache (Plesk): the scheme and the
        // client IP arrive in the X-Forwarded-* headers.
        $middleware->prepend(TrustProxies::class);

        // The locale cookie is written plaintext by SetLocale (a global middleware,
        // so it runs after the web group's encrypt phase) and must read back
        // plaintext too — exclude it from cookie encryption on both sides.
        $middleware->encryptCookies(['locale']);

        $middleware->alias([
            'auth' => Authenticate::class,
            'guest' => RedirectIfAuthenticated::class,
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        // Unauthenticated users land on the secret login path (query cleared).
        $middleware->redirectUsersTo(fn () => login_path());
        $middleware->redirectGuestsTo(fn () => login_path());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
