<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The audit of the public/ folder.
 *
 * Two things can silently break a Plesk deployment:
 *
 * 1. A FILE OR FOLDER IN public/ THAT SHADOWS A LARAVEL ROUTE. Apache serves
 *    /services from public/services before the framework ever sees the
 *    request — a leftover folder from the old Next.js app turns the page into
 *    a 404 (or a 500) with no PHP error at all.
 * 2. AN ASSET THE TEMPLATES REFERENCE THAT IS NOT IN THE REPOSITORY. The
 *    browser then draws a broken image box.
 *
 * Both are checked here, so the same rules can back the `public:check`
 * command and its test.
 */
final class PublicAssets
{
    /**
     * The public/ entries that are always fine.
     *
     * @var list<string>
     */
    public const ALLOWED_ENTRIES = [
        '.htaccess',
        'build',
        'favicon.ico',
        'images',
        'index.php',
        'robots.txt',
        'storage',
    ];

    /**
     * The legacy (Next.js) entries that may still sit in public/ on a host
     * that was deployed before the rebuild. Plesk never deletes files that
     * were removed from the repository, so they have to be cleaned by hand.
     *
     * @var list<string>
     */
    public const LEGACY_ENTRIES = [
        'services',
        'doctors',
        'next.svg',
        'vercel.svg',
        'file.svg',
        'globe.svg',
        'window.svg',
        'index.html',
        'index.htm',
    ];

    /**
     * The first path segment of every registered route.
     *
     * @return list<string>
     */
    public static function routePrefixes(): array
    {
        $prefixes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === '' || $uri === '/') {
                continue;
            }

            $segment = Str::before(trim($uri, '/'), '/');

            if ($segment !== '') {
                $prefixes[] = $segment;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * The entries in public/ whose name shadows a route.
     *
     * @return list<array{entry: string, route: string}>
     */
    public static function routeCollisions(): array
    {
        $prefixes = self::routePrefixes();
        $collisions = [];

        foreach (self::publicEntries() as $entry) {
            if (in_array($entry, self::ALLOWED_ENTRIES, true)) {
                continue;
            }

            if (in_array($entry, $prefixes, true)) {
                $collisions[] = ['entry' => $entry, 'route' => $entry];
            }
        }

        return $collisions;
    }

    /**
     * The names of the entries in public/ (files and folders, top level).
     *
     * @return list<string>
     */
    public static function publicEntries(): array
    {
        $path = public_path();
        $entries = [];

        if (! is_dir($path)) {
            return $entries;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entries[] = $entry;
        }

        sort($entries);

        return $entries;
    }

    /**
     * The public asset paths the templates and the seeders reference.
     *
     * @return list<string>
     */
    public static function referencedAssets(): array
    {
        $root = base_path();
        $found = [];

        $sources = [
            ...self::filesIn($root.'/resources/views', 'blade.php'),
            ...self::filesIn($root.'/database/seeders', 'php'),
            ...self::filesIn($root.'/app/Support/PublicSite', 'php'),
        ];

        foreach ($sources as $file) {
            $contents = (string) file_get_contents($file);

            // /images/... and /favicon.ico — the static files the pages load.
            if (preg_match_all('#(?<![\w:/])/(images/[A-Za-z0-9_\-./]+)#', $contents, $matches) > 0) {
                foreach ($matches[1] as $match) {
                    $found[] = '/'.ltrim($match, '/');
                }
            }

            if (str_contains($contents, '/favicon.ico')) {
                $found[] = '/favicon.ico';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The referenced assets that are NOT in public/.
     *
     * @return list<string>
     */
    public static function missingAssets(): array
    {
        $missing = [];

        foreach (self::referencedAssets() as $asset) {
            if (! is_file(public_path(ltrim($asset, '/')))) {
                $missing[] = $asset;
            }
        }

        return $missing;
    }

    /**
     * A message when favicon.ico is missing or empty, else null.
     */
    public static function faviconProblem(): ?string
    {
        $path = public_path('favicon.ico');

        if (! is_file($path)) {
            return 'public/favicon.ico is missing';
        }

        if (filesize($path) === 0) {
            return 'public/favicon.ico is empty';
        }

        return null;
    }

    /**
     * The built assets the Vite manifest promises.
     *
     * @return list<string>
     */
    public static function missingBuiltAssets(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return ['public/build/manifest.json is missing'];
        }

        $data = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($data)) {
            return ['public/build/manifest.json is not valid JSON'];
        }

        $missing = [];

        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry['file'])) {
                continue;
            }

            $file = 'build/'.$entry['file'];

            if (! is_file(public_path($file))) {
                $missing[] = '/'.ltrim($file, '/');
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function filesIn(string $directory, string $extension): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.'.$extension)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
