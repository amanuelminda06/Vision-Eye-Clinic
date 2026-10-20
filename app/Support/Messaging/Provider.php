<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * SMS delivery seam, ported from src/modules/messaging/provider.ts.
 *
 * This build ships only the manual `sms:`-link provider — the receptionist
 * sends from the clinic phone. Nothing is ever transmitted by the app.
 */
final class Provider
{
    public const MODE_MANUAL_LINK = 'MANUAL_LINK';

    public const MODE_GATEWAY = 'GATEWAY';

    /**
     * Detects the handset platform from a user agent; falls back to `other`.
     */
    public static function detectPlatform(?string $userAgent): string
    {
        $ua = mb_strtolower((string) $userAgent);

        if (preg_match('/iphone|ipad|ipod/', $ua) === 1) {
            return 'ios';
        }

        if (preg_match('/android/', $ua) === 1) {
            return 'android';
        }

        return 'other';
    }

    /**
     * Builds an `sms:` link. Android uses `?body=`, iOS uses `&body=`; `other`
     * falls back to the Android form. The body is percent-encoded.
     *
     * @throws \InvalidArgumentException on an empty body
     */
    public static function buildSmsLink(string $phoneE164, string $body, string $platform = 'android'): string
    {
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Cannot build an SMS link with an empty body');
        }

        $separator = $platform === 'ios' ? '&' : '?';

        return 'sms:'.$phoneE164.$separator.'body='.self::encodeUriComponent($body);
    }

    /**
     * Builds a group `sms:` link addressed to several numbers.
     *
     * @param  list<string>  $phones
     *
     * @throws \InvalidArgumentException on no recipients or an empty body
     */
    public static function buildGroupSmsLink(array $phones, string $body, string $platform = 'android'): string
    {
        $recipients = array_values(array_filter(
            array_map(fn (string $phone): string => trim($phone), $phones),
            fn (string $phone): bool => $phone !== '',
        ));

        if ($recipients === []) {
            throw new \InvalidArgumentException('Cannot build a group SMS link with no recipients');
        }

        if (trim($body) === '') {
            throw new \InvalidArgumentException('Cannot build an SMS link with an empty body');
        }

        $separator = $platform === 'ios' ? '&' : '?';

        return 'sms:'.implode(',', $recipients).$separator.'body='.self::encodeUriComponent($body);
    }

    /**
     * `encodeURIComponent` semantics: everything is percent-encoded except
     * `A-Z a-z 0-9 - _ . ! ~ * ' ( )`.
     */
    private static function encodeUriComponent(string $value): string
    {
        $encoded = rawurlencode($value);

        // rawurlencode is stricter than encodeURIComponent for these six.
        return strtr($encoded, [
            '%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')', '%7E' => '~',
        ]);
    }

    /**
     * The provider for a mode. Unknown/blank modes fall back to manual.
     *
     * @return array{mode: string, supportsBulkIndividual: bool}
     */
    public static function getSmsProvider(?string $mode): array
    {
        if ($mode === self::MODE_GATEWAY) {
            return ['mode' => self::MODE_GATEWAY, 'supportsBulkIndividual' => true];
        }

        return ['mode' => self::MODE_MANUAL_LINK, 'supportsBulkIndividual' => false];
    }
}
