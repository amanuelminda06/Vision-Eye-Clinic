<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

use Illuminate\Container\Container;

/**
 * SEO metadata, ported from src/modules/public-site/seo.ts.
 */
final class Seo
{
    /**
     * The site's base URL (trailing slash stripped).
     *
     * Reads `app.public_base_url` (seeded from the PUBLIC_BASE_URL env var)
     * and falls back to the env var itself, so the helper also works outside
     * a booted application. `app.url` (APP_URL) is the next fallback.
     *
     * The scheme is NEVER plain http in production: a canonical URL or an
     * og:url on http:// is mixed content on an https page, and the crawlers
     * treat it as a different site. The fallback is https too — an http
     * default would quietly break every absolute link the day APP_URL was
     * forgotten.
     */
    public static function siteBaseUrl(): string
    {
        $config = Container::getInstance()->bound('config')
            ? Container::getInstance()->make('config')
            : null;

        $base = $config !== null ? (string) $config->get('app.public_base_url', '') : '';

        if ($base === '') {
            $base = (string) env('PUBLIC_BASE_URL', '');
        }

        if ($base === '' && $config !== null) {
            $base = (string) $config->get('app.url', '');
        }

        if ($base === '') {
            $base = 'https://localhost';
        }

        $base = rtrim($base, '/');

        if (self::isProduction() && str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, strlen('http://'));
        }

        return $base;
    }

    /**
     * True when the application runs in production.
     */
    private static function isProduction(): bool
    {
        $config = Container::getInstance()->bound('config')
            ? Container::getInstance()->make('config')
            : null;

        return $config !== null && $config->get('app.env') === 'production';
    }

    /**
     * The metadata of one public page.
     *
     * @return array<string, mixed>
     */
    public static function pageMetadata(string $title, string $description, string $path, bool $isHome = false): array
    {
        $base = self::siteBaseUrl();

        return [
            'title' => $isHome ? $title : $title.' · Vision Eye Clinic',
            'description' => $description,
            'canonical' => $path,
            'languages' => [
                'en' => $path.'?lang=en',
                'am' => $path.'?lang=am',
                'x-default' => $path,
            ],
            'openGraph' => [
                'type' => 'website',
                'siteName' => 'Vision Eye Clinic',
                'title' => $title,
                'description' => $description,
                'url' => $base.$path,
                'locale' => 'en_ET',
                'alternateLocale' => ['am_ET'],
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
            ],
        ];
    }
}
