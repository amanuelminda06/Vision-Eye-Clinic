<?php

/**
 * Parse every PHP file — and every COMPILED Blade template — against a
 * specific PHP grammar.
 *
 *     php scripts/php-version-check.php [8.3]
 *
 * The app supports PHP 8.3 (the oldest version the deployment guide allows),
 * so nothing may use syntax that only exists in a newer version.
 *
 * The Blade templates are checked too: a template is compiled to PHP before
 * it runs, so it has to parse as PHP 8.3 as well. That is how the sitemap's
 * raw `<?xml` prolog was found — with `short_open_tag` On (the PHP default
 * when no php.ini is loaded) the lexer reads it as a short open tag and the
 * whole document becomes a parse error.
 *
 * Exits non-zero when anything fails to parse.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$root = dirname(__DIR__);

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$version = $argv[1] ?? '8.3';

$parser = (new ParserFactory)->createForVersion(PhpVersion::fromString($version));

/**
 * @return list<string>
 */
$scan = static function (string $path, string $suffix): array {
    $files = [];

    if (! is_dir($path)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.'.$suffix)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
};

$phpFiles = [];

foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
    $phpFiles = [...$phpFiles, ...$scan($root.'/'.$directory, 'php')];
}

$bladeFiles = $scan($root.'/resources/views', 'blade.php');

$failures = [];
$count = 0;

foreach ($phpFiles as $file) {
    $count++;

    try {
        $parser->parse((string) file_get_contents($file));
    } catch (Error $error) {
        $failures[] = str_replace($root.'/', '', $file).': '.$error->getMessage();
    }
}

foreach ($bladeFiles as $file) {
    $count++;

    try {
        $compiled = Blade::compileString((string) file_get_contents($file));
    } catch (Throwable $e) {
        $failures[] = str_replace($root.'/', '', $file).': compile failed: '.$e->getMessage();

        continue;
    }

    try {
        $parser->parse($compiled);
    } catch (Error $error) {
        $failures[] = str_replace($root.'/', '', $file).' (compiled): '.$error->getMessage();
    }
}

// A raw `<?xml` in a template is a short open tag when `short_open_tag` is
// On (the PHP default when no php.ini is loaded) — the document then becomes a
// parse error. The PHP parser does not reproduce that, so it is checked here.
foreach ($bladeFiles as $file) {
    if (preg_match('/<\?xml/i', (string) file_get_contents($file)) === 1) {
        $failures[] = str_replace($root.'/', '', $file)
            .': contains a raw `<?xml` — with short_open_tag On it is parsed as a short open tag.';
    }
}

foreach ($failures as $failure) {
    echo '✗ ', $failure, PHP_EOL;
}

printf(
    'PHP %s parse check: %d files (%d PHP + %d compiled templates), %d failures%s',
    $version,
    $count,
    count($phpFiles),
    count($bladeFiles),
    count($failures),
    PHP_EOL,
);

exit($failures === [] ? 0 : 1);
