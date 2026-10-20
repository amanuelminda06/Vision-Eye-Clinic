<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Port of the legacy gold standard src/lib/money.test.ts (+ spec §2.1 checks):
 * exact decimal everywhere, half-up rounding at each documented boundary,
 * NUMERIC(12,2) range handling, and the ETB display format.
 */
class MoneyTest extends TestCase
{
    public function test_normalizes_to_2_decimal_places(): void
    {
        $this->assertSame('10.50', Money::toDecimalString(Money::money('10.5')));
        $this->assertSame('0.00', Money::toDecimalString(Money::money('0')));
        $this->assertSame('1234.57', Money::toDecimalString(Money::money('1234.567')));
    }

    public function test_adds_without_float_errors(): void
    {
        $this->assertSame('0.30', Money::toDecimalString(Money::addMoney('0.10', '0.20')));
        $this->assertSame('120.00', Money::toDecimalString(Money::addMoney('19.99', '0.01', '100')));
        $this->assertSame('0.00', Money::toDecimalString(Money::addMoney()));
    }

    public function test_multiplies_unit_price_by_quantity(): void
    {
        $this->assertSame('599.97', Money::toDecimalString(Money::multiplyMoney('199.99', 3)));
        $this->assertSame('100.00', Money::toDecimalString(Money::lineTotal('50.00', 2)));
    }

    public function test_sums_sale_line_totals(): void
    {
        $this->assertSame('299.99', Money::toDecimalString(Money::sumLineTotals([
            ['unitPrice' => '100.00', 'quantity' => 2],
            ['unitPrice' => '33.33', 'quantity' => 3],
        ])));
    }

    public function test_rejects_fractional_quantities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::quantity(1.5);
    }

    public function test_rejects_fractional_quantity_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::quantity('2.5');
    }

    public function test_formats_with_the_clinic_currency(): void
    {
        $this->assertSame('ETB 1,234.50', Money::formatMoney('1234.5'));
        $this->assertSame('ETB 1,234.50', format_money('1234.5'));
        $this->assertSame('USD 1,234.50', Money::formatMoney('1234.5', 'USD'));
    }

    public function test_rounds_half_up_at_the_third_decimal(): void
    {
        $this->assertSame('0.01', Money::toDecimalString(Money::money('0.005')));
        $this->assertSame('0.00', Money::toDecimalString(Money::money('0.004')));
        $this->assertSame('0.01', Money::toDecimalString(Money::multiplyMoney('0.005', 1)));
        $this->assertSame('1.01', Money::toDecimalString(Money::multiplyMoney('1.005', 1)));
        $this->assertSame('0.01', Money::toDecimalString(Money::addMoney('0.005', '0.005')));
        // Negative ties round away from zero, exactly like decimal.js ROUND_HALF_UP.
        $this->assertSame('-1.01', Money::toDecimalString(Money::money('-1.005')));
    }

    public function test_handles_large_numeric_12_2_values_exactly(): void
    {
        $this->assertSame('9999999999.99', Money::toDecimalString(Money::addMoney('9999999999.99', '0')));
        $this->assertSame('9999999999.99', Money::toDecimalString(Money::money('9999999999.994')));
        $this->assertTrue(Money::isMoneyInRange('9999999999.99'));
        $this->assertTrue(Money::isMoneyInRange(Money::MAX_MONEY));
        $this->assertFalse(Money::isMoneyInRange('10000000000.00'));
        $this->assertFalse(Money::isMoneyInRange('-1'));
        $this->assertFalse(Money::isMoneyInRange('abc'));
    }

    public function test_averages_money_with_half_up_rounding(): void
    {
        $this->assertSame('25.00', Money::toDecimalString(Money::averageMoney('100.00', 4)));
        $this->assertSame('33.33', Money::toDecimalString(Money::averageMoney('100.00', 3)));
        $this->assertSame('0.03', Money::toDecimalString(Money::averageMoney('0.05', 2)));
        $this->assertSame('0.00', Money::toDecimalString(Money::averageMoney('500.00', 0)));
    }

    public function test_computes_percentage_change(): void
    {
        $this->assertSame(50.0, Money::percentChange('150.00', '100.00'));
        $this->assertSame(-50.0, Money::percentChange('50.00', '100.00'));
        $this->assertSame(33.3, Money::percentChange('133.33', '100.00'));
    }

    public function test_percent_change_is_null_when_previous_is_zero(): void
    {
        $this->assertNull(Money::percentChange('100.00', '0'));
    }

    public function test_zero_money_constant(): void
    {
        $this->assertSame('0.00', Money::ZERO_MONEY);
        $this->assertSame('0.00', Money::toDecimalString(Money::money('0')));
    }
}
