<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicSite\MapEmbed;
use PHPUnit\Framework\TestCase;

/**
 * The Google Maps embed URL.
 *
 * The embed must need NO API key, must carry the position from the CMS and
 * must disappear when the CMS has no position at all.
 */
class MapEmbedTest extends TestCase
{
    public function test_the_embed_url_carries_the_position_and_the_zoom(): void
    {
        $url = MapEmbed::embedUrl(9.0192, 38.7525, 16);

        $this->assertSame('https://www.google.com/maps?q=9.0192,38.7525&z=16&output=embed', $url);
    }

    public function test_the_embed_url_needs_no_api_key(): void
    {
        $url = (string) MapEmbed::embedUrl(9.0192, 38.7525, 16);

        $this->assertStringNotContainsString('key=', $url);
        $this->assertStringStartsWith('https://www.google.com/maps?', $url);
        $this->assertStringContainsString('output=embed', $url);
    }

    public function test_the_trailing_zeros_are_trimmed(): void
    {
        $url = MapEmbed::embedUrl(8.896235398615133, 38.77100409325467, 15);

        $this->assertSame('https://www.google.com/maps?q=8.896235,38.771004&z=15&output=embed', $url);
    }

    public function test_a_blank_position_has_no_embed(): void
    {
        // A blank CMS value reads as 0 — and 0,0 is in the ocean. A HALF-set
        // position is a CMS mistake, not a place: no map either.
        $this->assertNull(MapEmbed::embedUrl(0.0, 0.0));
        $this->assertNull(MapEmbed::embedUrl(0.0, 38.77));
        $this->assertNull(MapEmbed::embedUrl(8.89, 0.0));
    }

    public function test_an_impossible_position_has_no_embed(): void
    {
        $this->assertNull(MapEmbed::embedUrl(91.0, 38.77));
        $this->assertNull(MapEmbed::embedUrl(8.89, 181.0));
    }

    public function test_the_zoom_is_clamped_to_the_google_range(): void
    {
        $this->assertStringContainsString('&z=1&', (string) MapEmbed::embedUrl(8.89, 38.77, 0));
        $this->assertStringContainsString('&z=21&', (string) MapEmbed::embedUrl(8.89, 38.77, 99));
        $this->assertStringContainsString('&z=15&', (string) MapEmbed::embedUrl(8.89, 38.77));
    }

    public function test_the_position_test(): void
    {
        $this->assertTrue(MapEmbed::hasPosition(8.896235, 38.771004));
        $this->assertTrue(MapEmbed::hasPosition(-8.89, -38.77));
        $this->assertFalse(MapEmbed::hasPosition(0.0, 0.0));
        $this->assertFalse(MapEmbed::hasPosition(0.0, 38.77));
        $this->assertFalse(MapEmbed::hasPosition(8.89, 0.0));
        $this->assertFalse(MapEmbed::hasPosition(100.0, 38.77));
        $this->assertFalse(MapEmbed::hasPosition(8.89, 200.0));
    }
}
