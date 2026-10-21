#!/usr/bin/env php
<?php

/**
 * Write a value into the `.env` — safely.
 *
 *     php scripts/env-set.php DB_PASSWORD
 *
 * The value is read from a HIDDEN prompt (nothing is echoed, nothing lands in
 * the shell history) and written with the quoting Dotenv understands. A MySQL
 * password from Plesk can contain spaces, `#` and quotes — pasted raw into the
 * `.env` it either makes Dotenv throw (the site then dies with an Apache 500
 * before Laravel even starts) or silently truncates the password at the `#`.
 *
 * Everything else in the file is preserved. The value is never printed.
 *
 * Usage:
 *   php scripts/env-set.php <KEY>            prompt for the value
 *   php scripts/env-set.php <KEY> --show     print the current value
 *   php scripts/env-set.php --list           list the keys (never their values)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root.'/.env';

$args = array_slice($argv, 1);
$key = $args[0] ?? '';
$flag = $args[1] ?? '';

if ($key === '' || in_array($key, ['-h', '--help'], true)) {
    fwrite(STDERR, "usage: php scripts/env-set.php <KEY> [--show|--list]\n");
    exit(1);
}

if (! file_exists($file)) {
    fwrite(STDERR, "✗ .env not found in {$root}\n");
    exit(1);
}

/** Reads a value with the terminal echo off. */
$prompt = static function (string $question): string {
    fwrite(STDOUT, $question);
    $stty = @shell_exec('stty -g 2>/dev/null');
    if (is_string($stty) && $stty !== '') {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $value = fgets(STDIN);
    $value = $value === false ? '' : rtrim($value, "\r\n");

    if (is_string($stty) && $stty !== '') {
        @shell_exec('stty '.$stty.' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value;
};

$contents = (string) file_get_contents($file);
$lines = explode("\n", $contents);

/** The current value of a key (raw, unquoted, as Dotenv would read it). */
$current = static function (string $key) use ($lines): ?string {
    foreach ($lines as $line) {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m) === 1) {
            return $m[1];
        }
    }

    return null;
};

if ($flag === '--list') {
    foreach ($lines as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) === 1) {
            echo $m[1], PHP_EOL;
        }
    }

    exit(0);
}

if ($flag === '--show') {
    $value = $current($key);
    echo $value === null ? "(not set)\n" : $value."\n";
    exit(0);
}

// ── the new value ────────────────────────────────────────────────────────────
$value = $prompt("New value for {$key} (input hidden): ");

if ($value === '') {
    fwrite(STDERR, "✗ empty value — nothing changed\n");
    exit(1);
}

/**
 * Quote it the way Dotenv reads it: single quotes keep spaces, `#` and `$`
 * literal; a single quote inside is escaped with a backslash.
 */
$quoted = "'".str_replace("'", "\\'", $value)."'";

$replaced = false;
$output = [];

foreach ($lines as $line) {
    if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
        $output[] = $key.'='.$quoted;
        $replaced = true;

        continue;
    }

    $output[] = $line;
}

if (! $replaced) {
    $output[] = $key.'='.$quoted;
}

file_put_contents($file, implode("\n", $output));

echo "✓ {$key} updated (the value is not shown)\n";

exit(0);
