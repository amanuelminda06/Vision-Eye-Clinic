<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\Duplicates;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/modules/patients/duplicates.test.ts — the legacy gold standard
 * for name normalization, edit distance (Unicode-aware) and the
 * non-blocking "very similar name" warning.
 */
class DuplicatesTest extends TestCase
{
    public function test_normalize_lowercases_and_strips_punctuation_and_spaces(): void
    {
        $this->assertSame('mekdesalemu', Duplicates::normalizeNameForKey('  Mekdes,  Alemu. '));
    }

    public function test_normalize_keeps_non_latin_letters(): void
    {
        $this->assertSame('ሰላምገብረ', Duplicates::normalizeNameForKey('ሰላም ገብረ'));
    }

    public function test_normalize_treats_identical_spellings_as_equal_keys(): void
    {
        $this->assertSame(
            Duplicates::normalizeNameForKey('Mekdes Alemu'),
            Duplicates::normalizeNameForKey('MEKDES ALEMU'),
        );
    }

    public function test_levenshtein_is_zero_for_equal_strings(): void
    {
        $this->assertSame(0, Duplicates::levenshtein('abebe', 'abebe'));
    }

    public function test_levenshtein_counts_single_edits(): void
    {
        $this->assertSame(1, Duplicates::levenshtein('marta', 'martha'));
        $this->assertSame(1, Duplicates::levenshtein('mekdes', 'mekides'));
    }

    public function test_levenshtein_is_symmetric(): void
    {
        $this->assertSame(
            Duplicates::levenshtein('girma', 'german'),
            Duplicates::levenshtein('german', 'girma'),
        );
    }

    public function test_levenshtein_counts_unicode_characters_not_bytes(): void
    {
        // Two Amharic strings differing by one character → distance 1, not
        // the multi-byte byte difference.
        $this->assertSame(1, Duplicates::levenshtein('ሰላም', 'ሰላምም'));
    }

    public function test_is_similar_flags_a_one_letter_spelling_difference(): void
    {
        $this->assertTrue(Duplicates::isSimilarName('Mekdes Alemu', 'Mekides Alemu'));
    }

    public function test_is_similar_ignores_case_punctuation_and_extra_spaces(): void
    {
        $this->assertTrue(Duplicates::isSimilarName('Mekdes  Alemu', 'mekdes, alemu'));
    }

    public function test_is_similar_returns_true_for_identical_names(): void
    {
        $this->assertTrue(Duplicates::isSimilarName('Abebe Kebede', 'Abebe Kebede'));
    }

    public function test_is_similar_returns_false_for_clearly_different_names(): void
    {
        $this->assertFalse(Duplicates::isSimilarName('Abebe Kebede', 'Sara Tesfaye'));
    }

    public function test_is_similar_returns_false_for_empty_names(): void
    {
        $this->assertFalse(Duplicates::isSimilarName('', ''));
        $this->assertFalse(Duplicates::isSimilarName('Abebe', ''));
    }

    public function test_is_similar_allows_two_edits_only_for_long_names(): void
    {
        // 12 normalized chars → threshold 2.
        $this->assertTrue(Duplicates::isSimilarName('Abebech Kebede', 'Abebach Kabede'));
        // 6 normalized chars → threshold 1, so two edits are NOT similar.
        $this->assertFalse(Duplicates::isSimilarName('Abebech', 'Ababach'));
    }

    public function test_is_similar_requires_the_longer_name_to_have_five_characters(): void
    {
        // 'sara'(4) vs 'sarra'(5): longest is 5 → threshold 1, distance 1 → similar.
        $this->assertTrue(Duplicates::isSimilarName('Sara', 'Sarra'));
        // 'ana'(3) vs 'anna'(4): longest < 5 → never similar.
        $this->assertFalse(Duplicates::isSimilarName('Ana', 'Anna'));
    }
}
