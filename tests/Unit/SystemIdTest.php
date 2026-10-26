<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\SystemId;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the system-ID sequence: 2 letters + 4 digits, rolling
 * AA9999 -> AB0001, AZ9999 -> BA0001, and so on.
 */
class SystemIdTest extends TestCase
{
    public function test_the_first_id_is_aa0001(): void
    {
        $this->assertSame('AA0001', SystemId::first());
    }

    public function test_it_increments_the_numeric_suffix(): void
    {
        $this->assertSame('AA0002', SystemId::next('AA0001'));
        $this->assertSame('AA0010', SystemId::next('AA0009'));
        $this->assertSame('AA0100', SystemId::next('AA0099'));
        $this->assertSame('AA1000', SystemId::next('AA0999'));
    }

    public function test_it_rolls_the_number_into_the_next_letter_pair(): void
    {
        $this->assertSame('AB0001', SystemId::next('AA9999'));
        $this->assertSame('AC0001', SystemId::next('AB9999'));
        $this->assertSame('AZ0001', SystemId::next('AY9999'));
        $this->assertSame('BA0001', SystemId::next('AZ9999'));
        $this->assertSame('BB0001', SystemId::next('BA9999'));
    }

    public function test_it_validates_the_two_letter_four_digit_shape(): void
    {
        $this->assertTrue(SystemId::isValid('AA0001'));
        $this->assertTrue(SystemId::isValid('ZZ9999'));
        $this->assertFalse(SystemId::isValid('A00001'));
        $this->assertFalse(SystemId::isValid('AAA001'));
        $this->assertFalse(SystemId::isValid('aa0001'));
        $this->assertFalse(SystemId::isValid('AA001'));
        $this->assertFalse(SystemId::isValid(''));
    }

    public function test_it_parses_letters_and_number(): void
    {
        $this->assertSame(['letters' => 'AB', 'number' => 42], SystemId::parse('AB0042'));
    }

    public function test_it_rejects_parsing_a_malformed_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SystemId::parse('nope');
    }
}
