<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Privacy-safe IP hashing, ported from src/lib/ip.ts.
 *
 * The raw IP is never persisted — only a sha-256 hex digest, suitable for
 * abuse correlation on the public endpoints.
 */
final class Ip
{
    /**
     * The first `x-forwarded-for` hop, or null when the header is absent.
     */
    public static function clientIp(?string $forwardedFor = null): ?string
    {
        if ($forwardedFor === null) {
            return null;
        }

        $first = trim(explode(',', $forwardedFor)[0] ?? '');

        return $first === '' ? null : $first;
    }

    /**
     * The sha-256 hex digest of the client IP (empty string when there is
     * no IP at all).
     */
    public static function hashClientIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return '';
        }

        return hash('sha256', $ip);
    }
}
