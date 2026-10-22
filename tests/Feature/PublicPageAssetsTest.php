<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Staff;
use App\Support\PublicSite\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public pages and the files they load.
 *
 * The pages must render (200) and EVERY file they point at must exist: a
 * missing image, a missing stylesheet or a missing icon is what makes a page
 * look "partly broken".
 *
 * The classes the pages use must also be in the COMMITTED bundle. Tailwind
 * only emits the classes it finds in the sources, so a bundle that was not
 * rebuilt after a view changed leaves the page unstyled — that is exactly
 * the bug that shipped to production, and this test is the guard rail
 * against it.
 */
class PublicPageAssetsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pages a visitor sees.
     */
    private const PAGES = [
        '/',
        '/about',
        '/services',
        '/doctors',
        '/contact',
        '/faq',
        '/book',
        '/privacy',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A service and a doctor, so the pages really render the images.
        $this->publishContent();
    }

    /**
     * The public pages, in both languages.
     *
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        $cases = [];

        foreach (['en', 'am'] as $locale) {
            foreach (self::PAGES as $page) {
                $cases[$locale.' '.$page] = [$locale, $page];
            }
        }

        return $cases;
    }

    // ── the pages render ─────────────────────────────────────────────────────

    #[DataProvider('pages')]
    public function test_every_public_page_renders(string $locale, string $path): void
    {
        $response = $this->get($path.'?lang='.$locale);

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('<html lang="'.$locale.'"', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_the_login_page_renders(): void
    {
        $response = $this->get(login_path().'?lang=en');

        $response->assertOk();

        $html = (string) $response->getContent();

        // The login layout carries the icons too.
        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
    }

    // ── the files the pages load exist ──────────────────────────────────────

    #[DataProvider('pages')]
    public function test_every_file_a_public_page_loads_exists(string $locale, string $path): void
    {
        $html = (string) $this->get($path.'?lang='.$locale)->getContent();

        $files = $this->loadedFiles($html);

        $this->assertNotEmpty($files, "The page {$path} loads no file at all.");

        foreach ($files as $url) {
            $relative = $this->publicPathFor($url);

            if ($relative === null) {
                continue;
            }

            $this->assertFileExists(
                public_path($relative),
                "{$path} ({$locale}) loads '{$url}', but public/{$relative} does not exist.",
            );
        }
    }

    #[DataProvider('pages')]
    public function test_every_image_carries_a_size_an_alt_text_and_a_fallback(string $locale, string $path): void
    {
        $html = (string) $this->get($path.'?lang='.$locale)->getContent();

        foreach ($this->images($html) as $image) {
            $src = $image->getAttribute('src');

            $this->assertNotSame('', $src, "{$path} ({$locale}) has an <img> without a src.");

            $this->assertNotSame('', $image->getAttribute('alt'), "The image {$src} has no alt text.");

            $this->assertNotSame('', $image->getAttribute('width'), "The image {$src} has no width.");

            $this->assertNotSame('', $image->getAttribute('height'), "The image {$src} has no height.");

            // A missing file must never leave a broken box.
            $this->assertNotSame(
                '',
                $image->getAttribute('data-fallback'),
                "The image {$src} has no data-fallback.",
            );
        }
    }

    public function test_the_hero_image_is_eager_and_the_others_are_lazy(): void
    {
        $html = (string) $this->get('/?lang=en')->getContent();

        // The hero is the LCP element — it must not be lazy.
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);

        // Everything below the fold waits.
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    // ── the classes are in the committed bundle ──────────────────────────────

    #[DataProvider('pages')]
    public function test_every_class_a_public_page_uses_is_in_the_committed_stylesheet(string $locale, string $path): void
    {
        $html = (string) $this->get($path.'?lang='.$locale)->getContent();

        $available = $this->compiledClassNames();

        $this->assertNotEmpty($available, 'No class was found in the compiled stylesheets.');

        foreach ($this->usedClasses($html) as $class) {
            $this->assertContains(
                $class,
                $available,
                "{$path} ({$locale}) uses the class '{$class}', but it is NOT in the compiled CSS — "
                .'rebuild the assets (npm install && npm run build) and commit public/build.',
            );
        }
    }

    public function test_the_compiled_stylesheets_exist_and_cover_every_entry(): void
    {
        $stylesheets = $this->compiledStylesheets();

        $this->assertNotEmpty($stylesheets, 'The Vite manifest promises no stylesheet.');

        foreach ($stylesheets as $stylesheet) {
            $this->assertFileExists($stylesheet);
            $this->assertGreaterThan(0, filesize($stylesheet), "{$stylesheet} is empty.");
        }
    }

    public function test_every_file_the_compiled_css_references_exists(): void
    {
        foreach ($this->compiledStylesheets() as $stylesheet) {
            $contents = (string) file_get_contents($stylesheet);

            preg_match_all('#url\(([^)]+)\)#', $contents, $matches);

            foreach ($matches[1] as $reference) {
                $reference = trim($reference, " \t\n\r\"'");

                if ($reference === '' || str_starts_with($reference, 'data:') || str_starts_with($reference, '#')) {
                    continue;
                }

                $relative = ltrim((string) parse_url($reference, PHP_URL_PATH), '/');

                $this->assertFileExists(
                    public_path($relative),
                    "{$stylesheet} references '{$reference}', but public/{$relative} does not exist.",
                );
            }
        }
    }

    // ── the scheme ───────────────────────────────────────────────────────────

    public function test_the_asset_urls_are_https_on_the_production_origin(): void
    {
        // Behind the Plesk proxy the scheme is forced, and the origin comes
        // from APP_URL. Both must produce https — an http:// asset on an
        // https page is blocked as mixed content and silently disappears.
        URL::forceScheme('https');
        URL::forceRootUrl('https://visioneye.clinic.et');

        config(['app.public_base_url' => 'https://visioneye.clinic.et']);

        $html = (string) $this->get('/?lang=en')->getContent();

        $this->assertStringContainsString('src="https://visioneye.clinic.et/images/hero.jpg"', $html);
        $this->assertStringContainsString('href="https://visioneye.clinic.et/build/', $html);
        $this->assertStringContainsString('href="https://visioneye.clinic.et/favicon.ico"', $html);

        // Every file the page loads is on the https origin.
        foreach ($this->loadedFiles($html) as $url) {
            $this->assertStringStartsWith(
                'https://visioneye.clinic.et/',
                $url,
                "The page loads '{$url}' — it must be an absolute https URL on the public origin.",
            );
        }

        // No plain http on the page.
        $this->assertDoesNotMatchRegularExpression('#(?:src|href)="http://#', $html);

        // The canonical URL and the og:url are https too.
        $this->assertStringContainsString('rel="canonical" href="https://', $html);
        $this->assertStringContainsString('property="og:url" content="https://', $html);
    }

    public function test_the_canonical_base_url_is_never_plain_http_in_production(): void
    {
        config(['app.env' => 'production', 'app.public_base_url' => 'http://visioneye.clinic.et']);

        $this->assertSame('https://visioneye.clinic.et', Seo::siteBaseUrl());

        // And there is never a plain-http fallback.
        config(['app.public_base_url' => '', 'app.url' => '']);

        $base = Seo::siteBaseUrl();

        $this->assertStringStartsWith('https://', $base);
        $this->assertStringNotContainsString('localhost:3000', $base);
    }

    // ── the repository ───────────────────────────────────────────────────────

    public function test_the_legacy_public_folders_are_not_tracked_in_git(): void
    {
        $tracked = array_filter(array_map(
            'trim',
            explode("\n", (string) shell_exec(
                'cd '.escapeshellarg(base_path()).' && git ls-files public/ 2>/dev/null'
            )),
        ));

        $this->assertNotEmpty($tracked, 'git ls-files public/ returned nothing.');

        foreach ($tracked as $file) {
            foreach (['public/services/', 'public/doctors/'] as $legacy) {
                $this->assertStringStartsNotWith(
                    $legacy,
                    $file,
                    "{$file} is tracked in git — '{$legacy}' shadows the Laravel route of the same name. "
                    .'Move the assets under public/images/ and run php artisan public:clean-legacy.',
                );
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Publish a service and a doctor so the pages really render the images.
     */
    private function publishContent(): void
    {
        Service::factory()->create([
            'name' => 'General Eye Care',
            'name_en' => 'General Eye Care',
            'name_am' => 'አጠቃላይ የዓይን እክልከል',
            'active' => true,
            'is_public' => true,
            'image_url' => '/images/services/general-eye-care.png',
        ]);

        Staff::factory()->doctor()->create([
            'full_name' => 'Abebe Kebede',
            'photo_url' => '/images/doctors/dr-abebe-kebede.png',
        ]);
    }

    /**
     * The DOM of a rendered page.
     */
    private function dom(string $html): \DOMDocument
    {
        $document = new \DOMDocument;

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * The <img> elements of a rendered page.
     *
     * @return list<\DOMElement>
     */
    private function images(string $html): array
    {
        $images = [];

        foreach ($this->dom($html)->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }

        return $images;
    }

    /**
     * The files a page loads: the images, the scripts and the resource links.
     *
     * @return list<string>
     */
    private function loadedFiles(string $html): array
    {
        $document = $this->dom($html);
        $files = [];

        foreach ($document->getElementsByTagName('img') as $image) {
            $files[] = $image->getAttribute('src');
        }

        foreach ($document->getElementsByTagName('script') as $script) {
            $files[] = $script->getAttribute('src');
        }

        // Only the RESOURCE links — a canonical or an alternate points at a
        // page, not at a file.
        $resourceLinks = [
            'icon', 'shortcut icon', 'apple-touch-icon', 'apple-touch-icon-precomposed',
            'stylesheet', 'preload', 'prefetch', 'manifest', 'mask-icon',
        ];

        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));

            if (in_array($rel, $resourceLinks, true)) {
                $files[] = $link->getAttribute('href');
            }
        }

        return array_values(array_filter($files));
    }

    /**
     * The path under public/ a URL points at, or null when it is not a file
     * of this application (an external host, a data URI, a dynamic route).
     */
    private function publicPathFor(string $url): ?string
    {
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#') || str_starts_with($url, '//')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '' && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        // A dynamic route (an uploaded image) — not a file in public/.
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/uploads/')) {
            return null;
        }

        return ltrim($path, '/');
    }

    /**
     * The class names a rendered page uses.
     *
     * @return list<string>
     */
    private function usedClasses(string $html): array
    {
        $classes = [];

        if (preg_match_all('/class="([^"]*)"/', $html, $matches) > 0) {
            foreach ($matches[1] as $value) {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ($class !== '') {
                        $classes[$class] = true;
                    }
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * The stylesheets the Vite manifest promises.
     *
     * @return list<string>
     */
    private function compiledStylesheets(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($data)) {
            return [];
        }

        $stylesheets = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (array_merge([$entry['file'] ?? null], $entry['css'] ?? []) as $file) {
                if (is_string($file) && str_ends_with($file, '.css')) {
                    // The manifest paths are relative to public/build/.
                    $stylesheets[] = public_path('build/'.$file);
                }
            }
        }

        return array_values(array_unique($stylesheets));
    }

    /**
     * The class names the compiled stylesheets define.
     *
     * @return list<string>
     */
    private function compiledClassNames(): array
    {
        $names = [];

        foreach ($this->compiledStylesheets() as $stylesheet) {
            if (! is_file($stylesheet)) {
                continue;
            }

            foreach (self::cssClassSelectors((string) file_get_contents($stylesheet)) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The class names a stylesheet defines.
     *
     * A selector may be escaped (`.lg\:grid-cols-2`, `.bg-brand-50\/60`), so
     * the escapes are resolved — otherwise a class that IS in the bundle
     * would look missing.
     *
     * @return list<string>
     */
    private static function cssClassSelectors(string $css): array
    {
        $names = [];
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $char = $css[$i];

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = self::skipString($css, $i);

                continue;
            }

            if ($char !== '.') {
                $i++;

                continue;
            }

            $j = $i + 1;
            $name = '';

            while ($j < $length) {
                $next = $css[$j];

                if ($next === '\\') {
                    $name .= self::readEscape($css, $j);

                    continue;
                }

                if (str_contains(" \t\n\r{}(),>+~:[];%*#\"'", $next)) {
                    break;
                }

                $name .= $next;
                $j++;
            }

            if ($name !== '') {
                $names[$name] = true;
            }

            $i = $j;
        }

        return array_keys($names);
    }

    /**
     * Read one CSS escape sequence (the position points at the backslash).
     */
    private static function readEscape(string $css, int &$j): string
    {
        $length = strlen($css);

        $j++; // the backslash

        if ($j >= $length) {
            return '';
        }

        $char = $css[$j];

        if (ctype_xdigit($char)) {
            $hex = '';

            while ($j < $length && strlen($hex) < 6 && ctype_xdigit($css[$j])) {
                $hex .= $css[$j];
                $j++;
            }

            if ($j < $length && $css[$j] === ' ') {
                $j++;
            }

            $code = (int) hexdec($hex);

            return $code > 0 ? mb_chr($code, 'UTF-8') : '';
        }

        $j++;

        return $char;
    }

    /**
     * Skip a quoted string (the position points at the opening quote).
     */
    private static function skipString(string $css, int $i): int
    {
        $quote = $css[$i];
        $length = strlen($css);

        $i++;

        while ($i < $length) {
            if ($css[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($css[$i] === $quote) {
                return $i + 1;
            }

            $i++;
        }

        return $length;
    }
}
