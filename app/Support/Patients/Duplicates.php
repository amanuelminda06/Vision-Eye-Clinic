<?php

declare(strict_types=1);

namespace App\Support\Patients;

/**
 * Pure duplicate-patient helpers, ported 1:1 from src/modules/patients/duplicates.ts.
 *
 * The names are unicode-normalized with NFKC, lowercased, and stripped of
 * everything that is not a letter (\p{L}) — so punctuation, digits and spaces
 * vanish while Latin and Ethiopic (Amharic) letters survive. PHP's `/u` mode
 * mirrors JavaScript's `unicode` regex flag; `Normalizer::normalize` mirrors
 * `String.prototype.normalize('NFKC')`.
 */
final class Duplicates
{
    /** Normalize a full name to a comparison key (letters only, lowercased). */
    public static function normalizeNameForKey(string $name): string
    {
        $normalized = normalizer_normalize($name, \Normalizer::FORM_KC);

        if ($normalized === false) {
            // ICU unavailable for this input — fall back to the raw string so
            // comparison still works, matching the legacy "best effort" intent.
            $normalized = $name;
        }

        $lowered = mb_strtolower($normalized, 'UTF-8');

        // Drop every char that is not a unicode letter.
        return preg_replace('/[^\p{L}]/u', '', $lowered) ?? '';
    }

    /**
     * Classic Levenshtein edit distance (two-row DP). Operates on unicode
     * characters, not bytes, so Amharic names compare correctly.
     */
    public static function levenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $ac = mb_str_split($a);
        $bc = mb_str_split($b);

        $alen = count($ac);
        $blen = count($bc);

        if ($alen === 0) {
            return $blen;
        }

        if ($blen === 0) {
            return $alen;
        }

        $prev = range(0, $blen);
        $curr = array_fill(0, $blen + 1, 0);

        for ($i = 1; $i <= $alen; $i++) {
            $curr[0] = $i;

            for ($j = 1; $j <= $blen; $j++) {
                $cost = $ac[$i - 1] === $bc[$j - 1] ? 0 : 1;
                $curr[$j] = min(
                    $curr[$j - 1] + 1,
                    $prev[$j] + 1,
                    $prev[$j - 1] + $cost,
                );
            }

            [$prev, $curr] = [$curr, $prev];
        }

        return $prev[$blen];
    }

    /**
     * True when two names are VERY similar (likely the same person):
     * identical after normalization, or within a small edit distance that
     * scales with the length of the longer name.
     */
    public static function isSimilarName(string $a, string $b): bool
    {
        $na = self::normalizeNameForKey($a);
        $nb = self::normalizeNameForKey($b);

        if ($na === '' || $nb === '') {
            return false;
        }

        if ($na === $nb) {
            return true;
        }

        // JS String.length is UTF-16 code units, not characters — match it so
        // the length threshold behaves identically (matters only for astral
        // code points, which names effectively never contain).
        $longest = max(self::utf16Length($na), self::utf16Length($nb));

        if ($longest < 5) {
            return false;
        }

        $threshold = $longest >= 10 ? 2 : 1;

        return self::levenshtein($na, $nb) <= $threshold;
    }

    /** UTF-16 code-unit length of a UTF-8 string (mirrors JS String.length). */
    private static function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
