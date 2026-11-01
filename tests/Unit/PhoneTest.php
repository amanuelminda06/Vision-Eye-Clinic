<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * Phone normalization: E.164 output, parse error keys, display formatting and
 * search fragment variants. The legacy strict mobile-only rule was relaxed to
 * accept every real phone (landlines, other country codes) while still
 * rejecting short codes and non-numeric junk.
 */
class PhoneTest extends TestCase
{
    public function test_normalizes_supported_ethiopian_mobile_formats(): void
    {
        $this->assertSame('+251912345678', Phone::toE164('0912345678'));
        $this->assertSame('+251912345678', Phone::toE164('912345678'));
        $this->assertSame('+251912345678', Phone::toE164('+251912345678'));
        $this->assertSame('+251912345678', Phone::toE164(' 0912 345 678 '));
        $this->assertSame('+251912345678', Phone::toE164('0912-345-678'));
        $this->assertSame('+251912345678', Phone::toE164('+251 912 345 678'));
        $this->assertSame('+251912345678', Phone::toE164('(0912) 345.678'));
    }

    public function test_accepts_the_07_mobile_prefix_in_every_supported_form(): void
    {
        $this->assertSame('+251712345678', Phone::toE164('0712345678'));
        $this->assertSame('+251712345678', Phone::toE164('712345678'));
        $this->assertSame('+251712345678', Phone::toE164('+251712345678'));
        $this->assertSame('+251712345678', Phone::toE164('0712-345-678'));
        $this->assertSame('+251712345678', Phone::toE164(' 07 12 34 56 78 '));
    }

    public function test_accepts_ethiopian_landlines_and_other_international_numbers(): void
    {
        // The strict mobile-only rule was removed: any real phone is allowed.
        $this->assertSame('+251111234567', Phone::toE164('0111234567'));
        $this->assertSame('+251111234567', Phone::toE164('+251111234567'));
        $this->assertSame('+251811234567', Phone::toE164('+251811234567'));
        $this->assertSame('+12025550123', Phone::toE164('+12025550123'));
        $this->assertSame('+12025550123', Phone::toE164('12025550123'));
        $this->assertSame('+12025550123', Phone::toE164('0012025550123'));
    }

    public function test_rejects_malformed_numbers(): void
    {
        // Wrong length for an Ethiopian number (must be +251 + nine digits).
        $this->assertNull(Phone::toE164('091234567'));
        $this->assertNull(Phone::toE164('09123456789'));
        $this->assertNull(Phone::toE164('071234567'));
        $this->assertNull(Phone::toE164('+25191234567'));
        // Short codes and non-numeric junk.
        $this->assertNull(Phone::toE164('12345'));
        $this->assertNull(Phone::toE164(''));
        $this->assertNull(Phone::toE164('   '));
        $this->assertNull(Phone::toE164('not-a-phone'));
        $this->assertNull(Phone::toE164('telephone'));
        $this->assertNotNull(Phone::toE164('0912345678'));
    }

    public function test_rejects_null_and_non_string_input(): void
    {
        $this->assertNull(Phone::toE164(null));
        // Legacy normalizePhone(912345678) → null (JS has no types). PHP's
        // equivalent guard is the ?string type under strict_types.
        $this->expectException(\TypeError::class);
        Phone::toE164(912345678);
    }

    public function test_is_valid_mirrors_normalize(): void
    {
        $this->assertTrue(Phone::isValid('0912345678'));
        $this->assertTrue(Phone::isValid('0712345678'));
        $this->assertTrue(Phone::isValid('0111234567'));
        $this->assertFalse(Phone::isValid('12345'));
        $this->assertFalse(Phone::isValid('not-a-phone'));
    }

    public function test_parse_returns_the_normalized_value_on_success(): void
    {
        $this->assertSame(['ok' => true, 'value' => '+251912345678'], Phone::parse('0912345678'));
        $this->assertSame(['ok' => true, 'value' => '+251712345678'], Phone::parse('0712345678'));
    }

    public function test_parse_distinguishes_empty_from_invalid_input(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'required'], Phone::parse(''));
        $this->assertSame(['ok' => false, 'error' => 'required'], Phone::parse('   '));
        $this->assertSame(['ok' => false, 'error' => 'required'], Phone::parse(null));
        $this->assertSame(['ok' => false, 'error' => 'invalidPhone'], Phone::parse('12345'));
        $this->assertSame(['ok' => false, 'error' => 'invalidPhone'], Phone::parse('not-a-phone'));
    }

    public function test_search_variants_expand_09_and_07_fragments(): void
    {
        $variants = Phone::phoneSearchVariants('0912 34');
        $this->assertContains('91234', $variants);
        $this->assertContains('+25191234', $variants);
        $this->assertContains('091234', $variants);

        $variants = Phone::phoneSearchVariants('0712');
        $this->assertContains('712', $variants);
        $this->assertContains('+251712', $variants);

        $variants = Phone::phoneSearchVariants('0912');
        $this->assertContains('0912', $variants);
        $this->assertContains('+251912', $variants);
        $this->assertContains('912', $variants);
    }

    public function test_search_variants_pass_plain_digit_fragments_through(): void
    {
        $this->assertSame(['912345'], Phone::phoneSearchVariants('912345'));
    }

    public function test_search_variants_expand_partial_e164_input(): void
    {
        $variants = Phone::phoneSearchVariants('+2519123');
        $this->assertContains('2519123', $variants);
        $this->assertContains('+2519123', $variants);

        $variants = Phone::phoneSearchVariants('251912345');
        $this->assertContains('251912345', $variants);
        $this->assertContains('+251912345', $variants);
    }

    public function test_search_variants_null_when_no_digits(): void
    {
        $this->assertNull(Phone::phoneSearchVariants('abc'));
        $this->assertNull(Phone::phoneSearchVariants(''));
        $this->assertNull(Phone::phoneSearchVariants('ab cd'));
        $this->assertNull(Phone::phoneSearchVariants(null));
    }

    public function test_display_renders_e164_ethiopian_numbers_nationally(): void
    {
        $this->assertSame('0912345678', Phone::display('+251912345678'));
        $this->assertSame('0754321098', Phone::display('+251754321098'));
    }

    public function test_display_leaves_other_e164_numbers_untouched(): void
    {
        $this->assertSame('+971501234567', Phone::display('+971501234567'));
        $this->assertNull(Phone::display(null));
    }
}
