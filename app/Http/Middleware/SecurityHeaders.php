<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers, ported verbatim from next.config.ts securityHeaders:
 * - Content-Security-Policy (exact directive list; unsafe-eval / ws: only in dev)
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: DENY
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: camera=(), microphone=(), geolocation=()
 * - Strict-Transport-Security: max-age=63072000; includeSubDomains (production only)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isDev = config('app.env') !== 'production';

        $csp = implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'".($isDev ? " 'unsafe-eval'" : ''),
            "style-src 'self' 'unsafe-inline'",
            // No external image source: the fonts are self-hosted and the
            // map is an <iframe> (the framed document is Google\'s own, with
            // its own CSP).
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'".($isDev ? ' ws: wss:' : ''),
            // The Google Maps embed (footer + contact page). Google serves the
            // framed document from this host and sends its own CSP, so this
            // one source is all the map needs.
            "frame-src 'self' https://www.google.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if (! $isDev) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
