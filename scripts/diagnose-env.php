<?php

declare(strict_types=1);
use Dotenv\Dotenv;

/**
 * Show the state of the `.env` — WITHOUT printing a single value.
 *
 *     php scripts/diagnose-env.php
 *
 * It answers the three questions that matter when the site misbehaves:
 *
 * 1. Is the `.env` there, and which keys are MISSING / EMPTY / set?
 * 2. Can Dotenv actually parse it? (an unquoted value with a space makes
 *    Dotenv throw — the app then dies before Laravel starts and the web
 *    server shows a bare 500 with nothing in the Laravel log)
 * 3. What does the app actually read for each key?
 *
 * Only lengths are printed — never the values.
 */
$root = dirname(__DIR__);
$file = $root.'/.env';

$keys = [
    'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'PUBLIC_BASE_URL',
    'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'LOGIN_SECRET_PATH', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
    'OWNER_EMAIL', 'OWNER_PASSWORD', 'RECEPTION_EMAIL', 'RECEPTION_PASSWORD',
    'STAFF_EMAIL', 'STAFF_PASSWORD',
];

if (! is_file($file)) {
    echo '✗ .env is MISSING at ', $root, PHP_EOL;
    echo '  create it:  cp .env.example .env && php artisan key:generate', PHP_EOL;

    exit(1);
}

echo '• .env: ', $file, ' (', filesize($file), ' bytes)', PHP_EOL;
echo PHP_EOL;

$lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

foreach ($keys as $key) {
    $value = null;

    foreach ($lines as $line) {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $matches) === 1) {
            $value = trim($matches[1]);
            break;
        }
    }

    $value = $value === null ? null : trim($value, " \t\"'");

    $state = match (true) {
        $value === null => 'MISSING',
        $value === '' => 'EMPTY   <-- fill this in',
        default => 'set ('.strlen($value).' chars)',
    };

    printf('  %-18s %s%s', $key, $state, PHP_EOL);
}

echo PHP_EOL, '• can Dotenv parse it?', PHP_EOL;

$dir = sys_get_temp_dir().'/envcheck'.uniqid();
@mkdir($dir);
copy($file, $dir.'/.env');

require $root.'/vendor/autoload.php';

try {
    Dotenv::createImmutable($dir)->safeLoad();
    echo '  ✓ the .env parses', PHP_EOL;
} catch (Throwable $e) {
    echo '  ✗ ', $e::class, ': ', $e->getMessage(), PHP_EOL;
    echo '    The site cannot start with this file. Write the value with:', PHP_EOL;
    echo '      php scripts/env-set.php <KEY>', PHP_EOL;
} finally {
    @unlink($dir.'/.env');
    @rmdir($dir);
}

exit(0);
