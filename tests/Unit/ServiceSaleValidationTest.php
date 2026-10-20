<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sales\Constants;
use App\Support\Sales\SaleValidation;
use App\Support\Services\ServiceValidation;
use PHPUnit\Framework\TestCase;

/**
 * Form validation for the service and sale forms, ported from the zod schemas
 * in src/modules/services/schemas.ts and src/modules/sales/schemas.ts.
 */
class ServiceSaleValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validService(): array
    {
        return [
            'nameEn' => 'Eye Consultation',
            'nameAm' => '',
            'category' => 'consultation',
            'currentPrice' => '300.00',
            'isPublic' => 'true',
            'publicDescriptionEn' => '',
            'publicDescriptionAm' => '',
            'receiptLabelEn' => '',
            'receiptLabelAm' => '',
            'displayOrder' => '',
            'imageUrl' => '',
        ];
    }

    public function test_a_valid_service_form_produces_normalized_values(): void
    {
        $result = ServiceValidation::serviceForm($this->validService());

        $this->assertSame([], $result['fields']);
        $this->assertSame('Eye Consultation', $result['values']['nameEn']);
        $this->assertNull($result['values']['nameAm']);
        $this->assertTrue($result['values']['isPublic']);
        $this->assertNull($result['values']['publicDescriptionEn']);
        $this->assertSame(0, $result['values']['displayOrder']);
    }

    public function test_the_english_name_is_required_and_capped(): void
    {
        $missing = $this->validService();
        $missing['nameEn'] = '   ';
        $this->assertSame('required', ServiceValidation::serviceForm($missing)['fields']['nameEn']);

        $tooLong = $this->validService();
        $tooLong['nameEn'] = str_repeat('a', ServiceValidation::MAX_SERVICE_NAME + 1);
        $this->assertSame('tooLong', ServiceValidation::serviceForm($tooLong)['fields']['nameEn']);
    }

    public function test_the_category_must_be_one_of_the_fixed_keys(): void
    {
        $values = $this->validService();
        $values['category'] = 'bogus';

        $this->assertSame('invalidCategory', ServiceValidation::serviceForm($values)['fields']['category']);
    }

    public function test_the_price_must_match_the_numeric_shape_and_range(): void
    {
        foreach (['abc', '-1', '1.234', '10000000000.00'] as $bad) {
            $values = $this->validService();
            $values['currentPrice'] = $bad;
            $this->assertSame('invalidPrice', ServiceValidation::serviceForm($values)['fields']['currentPrice'], $bad);
        }

        $empty = $this->validService();
        $empty['currentPrice'] = '';
        $this->assertSame('required', ServiceValidation::serviceForm($empty)['fields']['currentPrice']);

        $max = $this->validService();
        $max['currentPrice'] = '9999999999.99';
        $this->assertSame([], ServiceValidation::serviceForm($max)['fields']);
    }

    public function test_optional_text_fields_are_capped_and_promoted_to_null(): void
    {
        $values = $this->validService();
        $values['publicDescriptionEn'] = str_repeat('x', ServiceValidation::MAX_PUBLIC_DESCRIPTION + 1);
        $this->assertSame('tooLong', ServiceValidation::serviceForm($values)['fields']['publicDescriptionEn']);

        $values = $this->validService();
        $values['receiptLabelEn'] = str_repeat('x', ServiceValidation::MAX_SERVICE_NAME + 1);
        $this->assertSame('tooLong', ServiceValidation::serviceForm($values)['fields']['receiptLabelEn']);

        $values = $this->validService();
        $values['imageUrl'] = str_repeat('x', ServiceValidation::MAX_IMAGE_URL + 1);
        $this->assertSame('tooLong', ServiceValidation::serviceForm($values)['fields']['imageUrl']);
    }

    public function test_display_order_floors_and_defaults_to_zero(): void
    {
        $this->assertSame(3, ServiceValidation::displayOrder('3'));
        $this->assertSame(4, ServiceValidation::displayOrder('4.9'));
        $this->assertSame(0, ServiceValidation::displayOrder('-2'));
        $this->assertSame(0, ServiceValidation::displayOrder('abc'));
        $this->assertSame(0, ServiceValidation::displayOrder(''));
    }

    public function test_an_image_upload_is_mime_and_size_checked(): void
    {
        $badType = ServiceValidation::serviceForm($this->validService(), ['mimeType' => 'image/gif', 'size' => 10]);
        $this->assertSame('imageInvalidType', $badType['fields']['image']);

        $tooLarge = ServiceValidation::serviceForm($this->validService(), [
            'mimeType' => 'image/png',
            'size' => ServiceValidation::MAX_SERVICE_IMAGE_BYTES + 1,
        ]);
        $this->assertSame('imageTooLarge', $tooLarge['fields']['image']);

        $ok = ServiceValidation::serviceForm($this->validService(), ['mimeType' => 'image/webp', 'size' => 1024]);
        $this->assertSame([], $ok['fields']);
        $this->assertTrue($ok['values']['hasImage']);
    }

    /** @return array<string, mixed> */
    private function validSale(): array
    {
        return [
            'patientId' => 'pat-1',
            'doctorId' => '',
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'lines' => [['serviceId' => 'svc-1', 'qty' => 2]],
        ];
    }

    public function test_a_valid_sale_form_is_accepted(): void
    {
        $result = SaleValidation::saleForm($this->validSale());

        $this->assertSame([], $result['fields']);
        $this->assertSame('CASH', $result['values']['paymentMethod']);
        $this->assertFalse($result['values']['walkIn']);
        $this->assertSame([['serviceId' => 'svc-1', 'qty' => 2]], $result['values']['lines']);
    }

    public function test_the_payment_method_must_be_known(): void
    {
        $values = $this->validSale();
        $values['paymentMethod'] = 'BITCOIN';

        $this->assertSame('invalidPaymentMethod', SaleValidation::saleForm($values)['fields']['paymentMethod']);
    }

    public function test_at_least_one_line_is_required(): void
    {
        $values = $this->validSale();
        $values['lines'] = [];

        $this->assertSame('noLines', SaleValidation::saleForm($values)['fields']['lines']);
    }

    public function test_line_quantities_are_validated(): void
    {
        foreach ([[['serviceId' => 's', 'qty' => 0], 'minQuantity'], [['serviceId' => 's', 'qty' => 1.5], 'invalidQuantity'], [['serviceId' => 's', 'qty' => 1000], 'tooMany']] as [$line, $expected]) {
            $values = $this->validSale();
            $values['lines'] = [$line];
            $this->assertSame($expected, SaleValidation::saleForm($values)['fields']['lines'], $expected);
        }
    }

    public function test_more_lines_than_the_cap_are_rejected(): void
    {
        $values = $this->validSale();
        $values['lines'] = array_fill(0, Constants::MAX_SALE_LINES + 1, ['serviceId' => 's', 'qty' => 1]);

        $this->assertSame('tooManyLines', SaleValidation::saleForm($values)['fields']['lines']);
    }

    public function test_lines_parse_from_a_json_string_and_fail_to_empty(): void
    {
        $this->assertSame(
            [['serviceId' => 'svc-1', 'qty' => 2]],
            SaleValidation::parseLines('[{"serviceId":"svc-1","qty":2}]'),
        );
        $this->assertSame([], SaleValidation::parseLines('not json'));
        $this->assertSame([], SaleValidation::parseLines('{"a":1}'));
    }

    public function test_the_void_reason_is_trimmed_and_length_checked(): void
    {
        $this->assertSame('required', SaleValidation::voidReason(['reason' => '  ab '])['fields']['reason']);
        $this->assertSame('tooLong', SaleValidation::voidReason(['reason' => str_repeat('x', 301)])['fields']['reason']);
        $this->assertSame('Wrong amount charged', SaleValidation::voidReason(['reason' => '  Wrong amount charged  '])['value']);
        $this->assertSame([], SaleValidation::voidReason(['reason' => 'abc'])['fields']);
    }
}
