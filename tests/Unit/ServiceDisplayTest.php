<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Services\Display;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale-aware service name / receipt label rendering, ported from
 * src/modules/services/display.test.ts.
 */
class ServiceDisplayTest extends TestCase
{
    /** @return list<array{string, string, string|null, string|null, string}> */
    public static function nameCases(): array
    {
        return [
            ['en', 'English', 'English', 'አማርኛ', 'Legacy'],
            ['am', 'አማርኛ', 'English', 'አማርኛ', 'Legacy'],
            ['am', 'English', 'English', null, 'Legacy'],
            ['en', 'Legacy', null, null, 'Legacy'],
        ];
    }

    #[DataProvider('nameCases')]
    public function test_service_name_in(
        string $locale,
        string $expected,
        ?string $nameEn,
        ?string $nameAm,
        string $name,
    ): void {
        $this->assertSame($expected, Display::serviceNameIn($locale, [
            'name' => $name,
            'nameEn' => $nameEn,
            'nameAm' => $nameAm,
        ]));
    }

    public function test_receipt_label_uses_the_locale_label_when_present(): void
    {
        $source = ['receiptLabelEn' => 'Check-up', 'receiptLabelAm' => 'ክትትል', 'fallback' => 'Long name'];

        $this->assertSame('Check-up', Display::receiptLabelIn('en', $source));
        $this->assertSame('ክትትል', Display::receiptLabelIn('am', $source));
    }

    public function test_receipt_label_falls_back_to_the_other_language(): void
    {
        $this->assertSame('Check-up', Display::receiptLabelIn('am', [
            'receiptLabelEn' => 'Check-up', 'receiptLabelAm' => null, 'fallback' => 'X',
        ]));
        $this->assertSame('ክትትል', Display::receiptLabelIn('en', [
            'receiptLabelEn' => null, 'receiptLabelAm' => 'ክትትል', 'fallback' => 'X',
        ]));
    }

    public function test_receipt_label_falls_back_to_the_full_service_name(): void
    {
        foreach (['en', 'am'] as $locale) {
            $this->assertSame('Long name', Display::receiptLabelIn($locale, [
                'receiptLabelEn' => null, 'receiptLabelAm' => null, 'fallback' => 'Long name',
            ]));
        }
    }
}
