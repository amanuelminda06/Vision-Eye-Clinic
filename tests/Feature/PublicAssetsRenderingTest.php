<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\SiteContent;
use App\Models\Staff;
use App\Models\User;
use App\Support\PublicSite\Content;
use App\Support\PublicSite\PublicQueries;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public assets: the icons, the hero image, the map and the graceful
 * degradation when an image is missing.
 *
 * A missing image must never leave a broken box, and the map must point at
 * the clinic with the coordinates from the CMS.
 */
class PublicAssetsRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_layouts_reference_the_icons(): void
    {
        foreach (['/', '/book'] as $path) {
            $html = (string) $this->get($path)->getContent();

            $this->assertStringContainsString('href="/favicon.ico"', $html, $path);
            $this->assertStringContainsString('href="/images/favicon-32.png"', $html, $path);
            $this->assertStringContainsString('href="/images/favicon-192.png"', $html, $path);
            $this->assertStringContainsString('href="/images/apple-touch-icon.png"', $html, $path);
        }

        // The ERP layout too.
        $owner = User::factory()->create([
            'role' => Roles::OWNER,
            'must_change_password' => false,
        ]);

        $html = (string) $this->actingAs($owner)->get('/dashboard')->getContent();

        $this->assertStringContainsString('href="/favicon.ico"', $html);
        $this->assertStringContainsString('href="/images/apple-touch-icon.png"', $html);
    }

    public function test_the_open_graph_image_is_a_file_that_exists(): void
    {
        $html = (string) $this->get('/')->getContent();

        preg_match('#<meta property="og:image" content="([^"]+)"#', $html, $matches);

        $this->assertNotEmpty($matches, 'The home page has no og:image.');
        $this->assertStringEndsWith('/images/hero.jpg', $matches[1]);
        $this->assertTrue(public_asset_exists('/images/hero.jpg'));
    }

    public function test_the_home_page_shows_the_hero_image_with_a_fallback(): void
    {
        $html = (string) $this->get('/')->getContent();

        $this->assertStringContainsString('src="/images/hero.jpg"', $html);
        // A missing file must not leave a broken box.
        $this->assertStringContainsString('data-fallback="hide"', $html);
    }

    public function test_the_header_shows_the_logo(): void
    {
        $html = (string) $this->get('/')->getContent();

        $this->assertStringContainsString('src="/images/logo.png"', $html);
    }

    public function test_the_contact_page_carries_the_map_with_the_cms_coordinates(): void
    {
        // The CMS position.
        SiteContent::query()->updateOrCreate(['key' => 'map_lat'], ['value_en' => '9.0192', 'value_am' => '9.0192']);
        SiteContent::query()->updateOrCreate(['key' => 'map_lng'], ['value_en' => '38.7525', 'value_am' => '38.7525']);
        SiteContent::query()->updateOrCreate(['key' => 'map_zoom'], ['value_en' => '16', 'value_am' => '16']);

        $html = (string) $this->get('/contact')->getContent();

        $this->assertStringContainsString('id="site-map"', $html);
        $this->assertStringContainsString('data-lat="9.0192"', $html);
        $this->assertStringContainsString('data-lng="38.7525"', $html);
        $this->assertStringContainsString('data-zoom="16"', $html);

        // The fallback: a visible address and a Google Maps link.
        $this->assertStringContainsString('maps.app.goo.gl', $html);
    }

    public function test_the_contact_page_has_no_map_without_coordinates(): void
    {
        // A blank position hides the map (and keeps the address + the link).
        SiteContent::query()->updateOrCreate(['key' => 'map_lat'], ['value_en' => '', 'value_am' => '']);
        SiteContent::query()->updateOrCreate(['key' => 'map_lng'], ['value_en' => '', 'value_am' => '']);

        $html = (string) $this->get('/contact')->getContent();

        $this->assertStringNotContainsString('id="site-map"', $html);
        $this->assertStringContainsString('maps.app.goo.gl', $html);
    }

    public function test_the_doctor_photo_falls_back_to_the_initials(): void
    {
        Staff::factory()->create([
            'is_doctor' => true,
            'show_on_website' => true,
            'active' => true,
            'full_name' => 'Abebe Kebede',
        ]);

        $html = (string) $this->get('/doctors')->getContent();

        if (str_contains($html, 'data-fallback="initials"')) {
            $this->assertStringContainsString('data-fallback-text="', $html);
        }

        // The card always has a fallback: the initials block when there is no
        // photo at all.
        $this->assertTrue(
            str_contains($html, 'rounded-full bg-brand-100') || str_contains($html, 'data-fallback="initials"'),
            'The doctor card has no initials fallback.',
        );
    }

    public function test_the_service_image_falls_back_to_a_placeholder(): void
    {
        Service::factory()->create([
            'name' => 'General Eye Care',
            'active' => true,
            'is_public' => true,
            'image_url' => '/images/services/general-eye-care.png',
        ]);

        $html = (string) $this->get('/services')->getContent();

        $this->assertStringContainsString('data-fallback="placeholder"', $html);
    }

    public function test_the_map_tile_host_is_the_only_external_image_source(): void
    {
        $csp = (string) $this->get('/contact')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://tile.openstreetmap.org', $csp);
        $this->assertStringNotContainsString('maps.googleapis.com', $csp);
        $this->assertStringNotContainsString('maps.gstatic.com', $csp);
    }

    public function test_the_public_asset_helpers(): void
    {
        $this->assertTrue(public_asset_exists('/images/hero.jpg'));
        $this->assertFalse(public_asset_exists('/images/nope.jpg'));
        $this->assertFalse(public_asset_exists('/../.env'));
        $this->assertFalse(public_asset_exists(''));

        $this->assertSame('/images/hero.jpg', public_asset_url('/images/hero.jpg'));
        $this->assertNull(public_asset_url('/images/nope.jpg'));
    }

    public function test_the_resolved_cms_content_carries_the_map_position(): void
    {
        $content = PublicQueries::getSiteContent();

        foreach (['map_lat', 'map_lng', 'map_zoom'] as $key) {
            $this->assertArrayHasKey($key, $content);
            $this->assertNotSame('', (string) Content::pick('en', $content[$key]));
        }
    }
}
