<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/phone.test.ts + src/lib/phone-extra.test.ts (the legacy gold
 * standard): E.164 normalization, parse error keys, display formatting and
 * search fragment variants, exactly as the TypeScript originals assert them.
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

    public function test_rejects_non_mobile_or_malformed_numbers(): void
    {
        $this->assertNull(Phone::toE164('091234567'));
        $this->assertNull(Phone::toE164('09123456789'));
        $this->assertNull(Phone::toE164('071234567'));
        $this->assertNull(Phone::toE164('+251812345678'));
        $this->assertNull(Phone::toE164('+12025550123'));
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
        $this->assertFalse(Phone::isValid('0812345678'));
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
