<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SiteContent;
use App\Support\PublicSite\Content;
use Illuminate\Database\Seeder;

/**
 * Fills the CMS-lite `site_content` table with the clinic's starting copy.
 *
 * The copy comes from `Content::DEFAULT_CONTENT` (ported from the legacy
 * `src/modules/public-site/content.ts`), so the website and the content editor
 * stay in sync. Idempotent and non-destructive: only MISSING keys are created,
 * so a clinic's edited wording is never overwritten by a reseed.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Content::CONTENT_KEYS as $key) {
            $default = Content::DEFAULT_CONTENT[$key];

            SiteContent::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value_en' => $default['en'],
                    'value_am' => $default['am'],
                ],
            );
        }
    }
}
