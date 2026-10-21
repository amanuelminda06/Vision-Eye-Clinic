<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trust the reverse proxy in front of Apache.
 *
 * On Plesk the request arrives through nginx (or the Apache proxy module),
 * so `X-Forwarded-Proto` carries the real scheme and `X-Forwarded-For` the
 * real client IP. Without trusting them the app believes it is on plain HTTP
 * and generates http:// asset URLs — mixed content on an HTTPS site, and a
 * broken stylesheet.
 *
 * The trusted proxies default to the loopback (the proxy runs on the same
 * host) and can be widened with TRUSTED_PROXIES in the .env ("*" trusts
 * every proxy — only safe when the app is not directly reachable).
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this request.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) env('TRUSTED_PROXIES', ''));

        if ($configured === '') {
            return ['127.0.0.1', '::1'];
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map(
            'trim',
            explode(',', $configured),
        )));
    }

    /**
     * The headers the proxy sets.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_AWS_ELB;
}
