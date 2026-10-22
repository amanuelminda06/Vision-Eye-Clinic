<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The navigation state and the card motion on the public website.
 *
 * - The link of the page you are on is marked and wears the selected chip.
 * - The cards rise into place when they scroll in and lift under the pointer.
 * - The hidden state of the reveal only exists while JavaScript runs, so a
 *   script that fails never leaves the page blank.
 */
class PublicNavigationStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string> the page path and the link it must mark
     */
    public static function publicPages(): array
    {
        return [
            '/' => '/',
            '/about' => '/about',
            '/services' => '/services',
            '/doctors' => '/doctors',
            '/contact' => '/contact',
            '/faq' => '/faq',
        ];
    }

    public function test_the_current_page_is_marked_in_the_navigation(): void
    {
        foreach (static::publicPages() as $path => $href) {
            $html = (string) $this->get($path)->getContent();

            preg_match_all(
                '#<a\s+href="([^"]+)"\s+class="([^"]*)"\s+aria-current="page"#',
                $html,
                $matches,
                PREG_SET_ORDER,
            );

            $this->assertNotEmpty($matches, 'No link is marked on '.$path);

            foreach ($matches as $match) {
                $this->assertSame($href, $match[1], 'The wrong link is marked on '.$path);
                $this->assertStringContainsString('bg-brand-100', $match[2], 'The selected chip is missing on '.$path);
            }
        }
    }

    public function test_only_the_current_page_is_marked(): void
    {
        $html = (string) $this->get('/about')->getContent();

        preg_match_all('#<a\s+href="([^"]+)"[^>]*aria-current="page"#', $html, $matches);

        $links = array_unique($matches[1]);

        $this->assertSame(['/about'], array_values($links));
    }

    public function test_the_cards_rise_into_view_and_lift_under_the_pointer(): void
    {
        Service::factory()->create([
            'name' => 'General Eye Care',
            'active' => true,
            'is_public' => true,
        ]);

        Staff::factory()->create([
            'is_doctor' => true,
            'show_on_website' => true,
            'active' => true,
            'full_name' => 'Abebe Kebede',
        ]);

        foreach (['/', '/services', '/doctors', '/about', '/contact'] as $path) {
            $html = (string) $this->get($path)->getContent();

            preg_match_all('#<(?:article|figure|div|details)\s+class="([^"]*\bcard\b[^"]*)"#', $html, $matches);

            $this->assertNotEmpty($matches[1], 'No card on '.$path);

            foreach ($matches[1] as $class) {
                $this->assertStringContainsString('card-hover', $class, 'A card does not lift on '.$path);
                $this->assertStringContainsString('reveal', $class, 'A card does not rise on '.$path);
            }
        }
    }

    public function test_the_javascript_marker_is_present(): void
    {
        foreach (['/', '/about', '/book'] as $path) {
            $html = (string) $this->get($path)->getContent();

            $this->assertStringContainsString(
                "document.documentElement.classList.add('js')",
                $html,
                'The reveal would hide the cards with no JavaScript on '.$path,
            );
        }

        // The ERP shell uses the same styles.
        $owner = User::factory()->create([
            'role' => Roles::OWNER,
            'must_change_password' => false,
        ]);

        $html = (string) $this->actingAs($owner)->get('/dashboard')->getContent();

        $this->assertStringContainsString("document.documentElement.classList.add('js')", $html);
    }
}
