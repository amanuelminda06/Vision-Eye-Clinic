<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sales\Receipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Receipt-number formatting, ported from src/modules/sales/receipt.test.ts.
 *
 * The gap-free claim itself needs a database and is covered by Feature tests;
 * here we pin the pure formatting + clinic-timezone year rules.
 */
class ReceiptTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    public function test_it_pads_to_six_digits(): void
    {
        $this->assertSame(6, Receipt::RECEIPT_PAD);
        $this->assertSame('2026-000001', Receipt::formatReceiptNumber(2026, 1));
        $this->assertSame('2026-000123', Receipt::formatReceiptNumber(2026, 123));
        $this->assertSame('2026-999999', Receipt::formatReceiptNumber(2026, 999999));
    }

    public function test_it_rejects_invalid_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Receipt::formatReceiptNumber(2026, 0);
    }

    public function test_it_uses_the_clinic_timezone_for_the_year(): void
    {
        // 23:30 Addis (UTC+3) is still 2026; 00:30 the next day is 2027.
        $this->assertSame(2026, Receipt::receiptYear(new \DateTimeImmutable('2026-12-31T20:30:00Z'), self::TZ));
        $this->assertSame(2027, Receipt::receiptYear(new \DateTimeImmutable('2026-12-31T21:30:00Z'), self::TZ));
    }

    /** @return list<array{int, int, string}> */
    public static function paddedValues(): array
    {
        return [
            [2026, 1, '2026-000001'],
            [2026, 123, '2026-000123'],
            [2026, 999999, '2026-999999'],
            [2027, 7, '2027-000007'],
        ];
    }

    #[DataProvider('paddedValues')]
    public function test_padding_matrix(int $year, int $value, string $expected): void
    {
        $this->assertSame($expected, Receipt::formatReceiptNumber($year, $value));
    }
}
