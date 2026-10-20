<?php

/**
 * Converter: messages/{en,am}.json → lang/{en,am}/*.php
 *
 * Top-level namespace = file; nested structure preserved (Laravel resolves dots
 * through nested arrays). Run from the repo root:
 *
 *     php scripts/convert-messages.php && ./vendor/bin/pint lang
 *
 * The generated files are committed, so they are formatted to the project
 * standard (Pint) — never edit them by hand; edit the JSON and re-run.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Render a PHP value the way Pint would: short arrays, 4-space indent, one key
 * per line with a trailing comma. Scalars use single quotes.
 */
function exportValue(mixed $value, int $depth): string
{
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $indent = str_repeat(' ', ($depth + 1) * 4);
        $closeIndent = str_repeat(' ', $depth * 4);

        $lines = [];
        foreach ($value as $key => $item) {
            $keyPart = $isList ? '' : exportScalar((string) $key).' => ';
            $lines[] = $indent.$keyPart.exportValue($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".$closeIndent.']';
    }

    return exportScalar($value);
}

function exportScalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return var_export($value, true);
    }

    // Strings: single quotes, escape backslashes and single quotes only.
    return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
}

foreach (['en', 'am'] as $locale) {
    $source = $root."/messages/$locale.json";
    $json = json_decode((string) file_get_contents($source), true);

    if (! is_array($json)) {
        fwrite(STDERR, "failed to decode $source\n");
        exit(1);
    }

    $dir = $root."/lang/$locale";
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    foreach ($json as $namespace => $value) {
        $file = $dir.'/'.$namespace.'.php';
        $content = "<?php\n\n"
            ."// Generated from messages/$locale.json — do not edit by hand; edit the JSON and re-run scripts/convert-messages.php\n\n"
            .'return '.exportValue($value, 0).";\n";
        file_put_contents($file, $content);
        echo "wrote lang/$locale/$namespace.php\n";
    }
}

echo "done — run ./vendor/bin/pint lang to normalise\n";
