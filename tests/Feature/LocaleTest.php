<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Locale switching (shell spec §2 "Session & cookie"): ?lang=en|am sets the
 * `locale` cookie and switches the app locale; invalid values are ignored.
 *
 * The `locale` cookie is deliberately excluded from cookie encryption (see
 * bootstrap/app.php): SetLocale is a global middleware that runs after the web
 * group's encrypt phase, so the cookie is written and read as plaintext.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_amharic_is_selected_via_the_query_string(): void
    {
        $response = $this->get('/?lang=am');

        $response->assertOk();
        $this->assertSame('am', App::getLocale());
    }

    public function test_english_is_selected_via_the_query_string(): void
    {
        $response = $this->get('/?lang=en');

        $response->assertOk();
        $this->assertSame('en', App::getLocale());
    }

    public function test_the_html_lang_attribute_reflects_amharic(): void
    {
        $this->get('/?lang=am')->assertSee('lang="am"', false);
    }

    public function test_the_html_lang_attribute_defaults_to_english(): void
    {
        $this->get('/')->assertSee('lang="en"', false);
    }

    public function test_amharic_copy_is_rendered_through_the_catalog(): void
    {
        $this->get('/?lang=am')->assertSee(__('app.name', [], 'am'));
    }

    public function test_the_locale_cookie_is_set_for_a_valid_language(): void
    {
        // The cookie is intentionally NOT encrypted (see bootstrap/app.php), so
        // assert it as a plaintext value.
        $this->get('/?lang=am')->assertCookie('locale', 'am', encrypted: false);
    }

    public function test_an_invalid_language_is_ignored(): void
    {
        $this->get('/?lang=fr')
            ->assertOk()
            ->assertCookieMissing('locale');

        $this->assertSame('en', App::getLocale());
    }

    public function test_a_plaintext_locale_cookie_is_honoured_on_the_next_request(): void
    {
        // Laravel's test client threads response cookies into the next request;
        // this proves the cookie is not mangled by the encryption layer.
        $this->withUnencryptedCookie('locale', 'am')
            ->get('/')
            ->assertOk();

        $this->assertSame('am', App::getLocale());
    }

    public function test_a_foreign_locale_cookie_value_is_ignored(): void
    {
        $this->withUnencryptedCookie('locale', 'xx')
            ->get('/')
            ->assertOk();

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_locale_switcher_links_are_present_on_the_login_page(): void
    {
        $this->get('/login/'.(string) config('auth.login_secret_path'))
            ->assertOk()
            ->assertSee('?lang=am', false)
            ->assertSee('?lang=en', false);
    }
}
