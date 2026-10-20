<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\TagColors;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/modules/patients/tags.ts tagColorFor.
 *
 * The expected values below were produced by running the *legacy TypeScript*
 * through Node, so this test locks the PHP port to the same deterministic
 * palette (including UTF-16 charCodeAt semantics for Amharic tag names).
 */
class TagColorsTest extends TestCase
{
    public function test_palette_is_the_legacy_eight_colours(): void
    {
        $this->assertSame([
            '#3B82F6', '#10B981', '#F59E0B', '#EF4444',
            '#8B5CF6', '#06B6D4', '#EC4899', '#84CC16',
        ], TagColors::PALETTE);
    }

    public function test_matches_node_for_representative_names(): void
    {
        // Ground truth from `node` running the original tagColorFor().
        $this->assertSame('#F59E0B', TagColors::for('glasses'));
        $this->assertSame('#EF4444', TagColors::for('contact-lens'));
        $this->assertSame('#10B981', TagColors::for('Pediatric'));
        $this->assertSame('#06B6D4', TagColors::for('diabetes'));
        $this->assertSame('#84CC16', TagColors::for('follow-up'));
        $this->assertSame('#F59E0B', TagColors::for('z'));
        $this->assertSame('#3B82F6', TagColors::for(''));
    }

    public function test_amharic_names_use_utf16_code_units(): void
    {
        // charCodeAt iterates UTF-16 units — the PHP port must agree.
        $this->assertSame('#F59E0B', TagColors::for('ሰላም'));
        $this->assertSame('#EF4444', TagColors::for('አዲስ'));
    }

    public function test_is_deterministic_and_signed_overflow_safe(): void
    {
        // 32-bit signed overflow (`| 0`) must not leak into a negative index.
        $this->assertSame('#3B82F6', TagColors::for(str_repeat('a', 20)));
        $this->assertSame(TagColors::for('diabetes'), TagColors::for('diabetes'));
        $this->assertContains(TagColors::for('anything'), TagColors::PALETTE);
    }
}
