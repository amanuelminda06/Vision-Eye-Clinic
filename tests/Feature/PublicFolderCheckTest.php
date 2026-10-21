<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PublicAssets;
use Tests\TestCase;

/**
 * The public/ folder guard rails.
 *
 * A Plesk Git deployment never deletes a file that was removed from the
 * repository, so the leftovers of the old Next.js app (public/services,
 * public/doctors, the svg files) keep shadowing the Laravel routes. The
 * `public:clean-legacy` command removes them; `public:check` fails when they
 * (or a missing asset, or an empty favicon) are there.
 *
 * The last test runs the same check against the REPOSITORY — that is the one
 * that fails CI when somebody adds public/services again.
 */
class PublicFolderCheckTest extends TestCase
{
    /** The legacy entries the test creates (and removes again). */
    private const LEGACY_FILES = [
        'next.svg', 'vercel.svg', 'file.svg', 'globe.svg', 'window.svg', 'index.html', 'index.htm',
    ];

    protected function tearDown(): void
    {
        $this->removeLegacyEntries();

        parent::tearDown();
    }

    private function removeLegacyEntries(): void
    {
        foreach (['services', 'doctors'] as $directory) {
            $path = public_path($directory);

            if (is_dir($path)) {
                foreach (glob($path.'/*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($path);
            }
        }

        foreach (self::LEGACY_FILES as $file) {
            @unlink(public_path($file));
        }
    }

    private function createLegacyEntries(): void
    {
        foreach (['services', 'doctors'] as $directory) {
            @mkdir(public_path($directory), 0o775, true);
            @file_put_contents(public_path($directory.'/index.html'), '<html>legacy</html>');
        }

        foreach (self::LEGACY_FILES as $file) {
            @file_put_contents(public_path($file), 'legacy');
        }
    }

    // ── public:clean-legacy ──────────────────────────────────────────────────

    public function test_the_cleanup_command_lists_the_known_legacy_entries(): void
    {
        $this->assertContains('services', PublicAssets::LEGACY_ENTRIES);
        $this->assertContains('doctors', PublicAssets::LEGACY_ENTRIES);

        foreach (['next.svg', 'vercel.svg', 'file.svg', 'globe.svg', 'window.svg', 'index.html', 'index.htm'] as $file) {
            $this->assertContains($file, PublicAssets::LEGACY_ENTRIES);
        }
    }

    public function test_the_cleanup_command_removes_the_legacy_entries(): void
    {
        $this->createLegacyEntries();

        $this->artisan('public:clean-legacy')
            ->expectsOutputToContain('services removed')
            ->expectsOutputToContain('doctors removed')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(public_path('services'));
        $this->assertDirectoryDoesNotExist(public_path('doctors'));

        foreach (self::LEGACY_FILES as $file) {
            $this->assertFileDoesNotExist(public_path($file));
        }
    }

    public function test_the_cleanup_command_removes_nothing_else(): void
    {
        $this->createLegacyEntries();

        $keep = public_path('keep-me.txt');
        file_put_contents($keep, 'mine');

        $this->artisan('public:clean-legacy')->assertSuccessful();

        $this->assertFileExists($keep);
        $this->assertFileExists(public_path('index.php'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('.htaccess'));
        $this->assertDirectoryExists(public_path('images'));

        @unlink($keep);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $this->createLegacyEntries();

        $this->artisan('public:clean-legacy --dry-run')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        // Still there.
        $this->assertDirectoryExists(public_path('services'));
        $this->assertFileExists(public_path('next.svg'));
    }

    public function test_the_cleanup_command_is_happy_when_there_is_nothing_to_do(): void
    {
        $this->artisan('public:clean-legacy')
            ->expectsOutputToContain('not present')
            ->assertSuccessful();
    }

    // ── public:check ─────────────────────────────────────────────────────────

    public function test_the_check_command_fails_when_a_legacy_folder_shadows_a_route(): void
    {
        $this->createLegacyEntries();

        $this->artisan('public:check')
            ->expectsOutputToContain('shadows a Laravel route')
            ->assertFailed();
    }

    public function test_the_check_command_passes_on_a_clean_public_folder(): void
    {
        $this->artisan('public:check')
            ->expectsOutputToContain('public/ is ready')
            ->assertSuccessful();
    }

    public function test_the_check_command_fails_when_the_favicon_is_empty(): void
    {
        $favicon = public_path('favicon.ico');
        $backup = (string) file_get_contents($favicon);

        file_put_contents($favicon, '');

        try {
            $this->artisan('public:check')
                ->expectsOutputToContain('favicon.ico is empty')
                ->assertFailed();
        } finally {
            file_put_contents($favicon, $backup);
        }
    }

    public function test_the_check_command_fails_when_a_referenced_asset_is_missing(): void
    {
        $hero = public_path('images/hero.jpg');
        $backup = (string) file_get_contents($hero);

        unlink($hero);

        try {
            $this->artisan('public:check')
                ->expectsOutputToContain('referenced assets are missing')
                ->assertFailed();
        } finally {
            file_put_contents($hero, $backup);
        }
    }

    // ── the repository itself ────────────────────────────────────────────────

    public function test_the_repository_has_a_clean_public_folder(): void
    {
        // This is the test that fails CI when somebody adds public/services
        // (or public/doctors) again.
        $this->assertSame([], PublicAssets::routeCollisions(), 'public/ shadows a route.');
        $this->assertSame([], PublicAssets::missingAssets(), 'A referenced asset is missing.');
        $this->assertSame([], PublicAssets::missingBuiltAssets(), 'A built asset is missing.');
        $this->assertNull(PublicAssets::faviconProblem(), 'favicon.ico is missing or empty.');
    }

    public function test_every_tracked_public_entry_is_allowed(): void
    {
        $tracked = [];

        foreach (explode("\n", trim((string) shell_exec(
            'cd '.escapeshellarg(base_path()).' && git ls-files public/ 2>/dev/null'
        ))) as $line) {
            if ($line === '') {
                continue;
            }

            // The top-level entry: public/build/assets/x.js -> build
            $relative = substr($line, strlen('public/'));
            $tracked[] = str_contains($relative, '/')
                ? substr($relative, 0, strpos($relative, '/'))
                : $relative;
        }

        $this->assertNotEmpty($tracked, 'git ls-files public/ returned nothing.');

        foreach (array_unique($tracked) as $entry) {
            $this->assertContains(
                $entry,
                PublicAssets::ALLOWED_ENTRIES,
                "public/{$entry} is tracked but is not an allowed entry.",
            );
        }
    }

    public function test_the_favicon_is_a_real_icon(): void
    {
        $favicon = public_path('favicon.ico');

        $this->assertFileExists($favicon);
        $this->assertGreaterThan(0, filesize($favicon), 'favicon.ico is empty.');

        // The ICO magic number.
        $bytes = (string) file_get_contents($favicon, false, null, 0, 4);
        $this->assertSame("\x00\x00\x01\x00", $bytes, 'favicon.ico is not an ICO file.');
    }

    public function test_the_icon_assets_exist(): void
    {
        foreach ([
            '/images/favicon-32.png',
            '/images/favicon-192.png',
            '/images/apple-touch-icon.png',
            '/images/logo.png',
            '/images/logo-dark.png',
            '/images/logo.jpg',
            '/images/hero.jpg',
        ] as $asset) {
            $this->assertTrue(public_asset_exists($asset), "{$asset} is missing.");
        }
    }
}
