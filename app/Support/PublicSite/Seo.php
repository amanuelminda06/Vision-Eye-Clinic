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
     * a booted application.
     */
    public static function siteBaseUrl(): string
    {
        $base = '';

        if (Container::getInstance()->bound('config')) {
            $base = (string) config('app.public_base_url', '');
        }

        if ($base === '') {
            $base = (string) env('PUBLIC_BASE_URL', '');
        }

        if ($base === '') {
            return 'http://localhost:3000';
        }

        return rtrim($base, '/');
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
