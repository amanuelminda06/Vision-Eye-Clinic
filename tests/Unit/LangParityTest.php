<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Catalog integrity: the PHP lang trees are a lossless conversion of the
 * legacy next-intl JSON catalogs (25 namespaces, {placeholder} style kept),
 * and English/Amharic have complete leaf parity. Any drift here is a
 * translation bug in the UI.
 */
class LangParityTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * Flatten nested arrays to leaf paths: ['nav' => ['x' => 'y']] → 'nav.x'.
     *
     * @return array<string, string>
     */
    private static function flatten(array $in, string $prefix = ''): array
    {
        $out = [];

        foreach ($in as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Load lang/{locale}/*.php as one flat leaf map ("namespace.key" → string).
     *
     * @return array<string, string>
     */
    private static function langLeaves(string $locale): array
    {
        $out = [];

        foreach (glob(self::ROOT."/lang/{$locale}/*.php") ?: [] as $file) {
            $namespace = basename($file, '.php');
            $tree = require $file;

            foreach (self::flatten($tree) as $key => $value) {
                $out[$namespace.'.'.$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function jsonLeaves(string $locale): array
    {
        $json = json_decode(
            (string) file_get_contents(self::ROOT."/messages/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return self::flatten($json);
    }

    public function test_php_lang_tree_is_an_exact_conversion_of_the_json_catalog(): void
    {
        foreach (['en', 'am'] as $locale) {
            $json = self::jsonLeaves($locale);
            $php = self::langLeaves($locale);

            $this->assertSame(
                [],
                array_diff_key($json, $php),
                "lang/{$locale} is missing keys present in messages/{$locale}.json",
            );
            $this->assertSame(
                [],
                array_diff_key($php, $json),
                "lang/{$locale} has keys that messages/{$locale}.json does not",
            );

            foreach ($json as $key => $value) {
                $this->assertSame($value, $php[$key], "Value drift for {$key} in {$locale}");
            }
        }
    }

    public function test_english_and_amharic_have_complete_leaf_parity(): void
    {
        $en = self::langLeaves('en');
        $am = self::langLeaves('am');

        $this->assertSame([], array_diff_key($en, $am), 'Amharic is missing keys English has');
        $this->assertSame([], array_diff_key($am, $en), 'Amharic has keys English does not');
        // Locales legitimately differ in insertion order; parity is about the key set.
        $enKeys = array_keys($en);
        $amKeys = array_keys($am);
        sort($enKeys);
        sort($amKeys);
        $this->assertSame($enKeys, $amKeys);
        $this->assertCount(1200, $en);
        $this->assertCount(1200, $am);
    }

    public function test_every_leaf_is_a_non_empty_string_in_both_locales(): void
    {
        foreach (['en', 'am'] as $locale) {
            foreach (self::langLeaves($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "Empty leaf {$key} in {$locale}");
            }
        }
    }

    public function test_namespaces_match_between_json_and_php_trees(): void
    {
        $jsonNamespaces = array_keys(json_decode(
            (string) file_get_contents(self::ROOT.'/messages/en.json'),
            true,
        ));
        $phpNamespaces = array_map(
            static fn (string $f): string => basename($f, '.php'),
            glob(self::ROOT.'/lang/en/*.php') ?: [],
        );

        sort($jsonNamespaces);
        sort($phpNamespaces);

        $this->assertSame($jsonNamespaces, $phpNamespaces);
        $this->assertCount(25, $phpNamespaces);
    }

    public function test_placeholders_use_legacy_curly_brace_style(): void
    {
        // next-intl {name} placeholders survive the conversion so __t() can
        // substitute them; a stray :name would silently never be replaced.
        $sample = self::langLeaves('en');
        $this->assertArrayHasKey('app.name', $sample);

        $withPlaceholder = 0;
        foreach ($sample as $value) {
            if (preg_match('/\{\w+\}/', $value) === 1) {
                $withPlaceholder++;
            }
        }

        $this->assertGreaterThan(0, $withPlaceholder, 'Expected placeholders to exist in the catalog');
    }
}
