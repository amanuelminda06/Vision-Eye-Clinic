<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Support\Money;

/**
 * Service form validation, ported from src/modules/services/schemas.ts.
 *
 * Field errors are returned as `['field' => '<i18n key>']` — the view renders
 * `validation.<key>`. The FIRST issue per field wins and the order below
 * mirrors the zod schema so error precedence is identical.
 *
 * `image` is validated separately against the uploaded file (MIME + size);
 * callers pass the raw values and the optional file descriptor.
 */
final class ServiceValidation
{
    public const MAX_SERVICE_NAME = 120;

    public const MAX_PUBLIC_DESCRIPTION = 2000;

    public const MAX_IMAGE_URL = 500;

    public const MAX_SERVICE_IMAGE_BYTES = 1024 * 1024;

    /** @var array<string, string> */
    public const SERVICE_IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** Prices are NUMERIC(12,2): up to 10 integer digits, at most 2 decimals. */
    private const PRICE_RE = '/^\d{1,10}(\.\d{1,2})?$/';

    /** File extension for an accepted service image MIME type, or null. */
    public static function imageExtension(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        return self::SERVICE_IMAGE_EXTENSIONS[$mimeType] ?? null;
    }

    /**
     * Validate the create/edit service form.
     *
     * @param  array<string, mixed>  $values  raw form strings
     * @param  array{mimeType: string, size: int}|null  $image  uploaded file descriptor
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function serviceForm(array $values, ?array $image = null): array
    {
        $fields = [];

        $nameEn = trim(self::str($values['nameEn'] ?? ''));
        if ($nameEn === '') {
            $fields['nameEn'] = 'required';
        } elseif (mb_strlen($nameEn) > self::MAX_SERVICE_NAME) {
            $fields['nameEn'] = 'tooLong';
        }

        $nameAm = trim(self::str($values['nameAm'] ?? ''));
        if (mb_strlen($nameAm) > self::MAX_SERVICE_NAME) {
            $fields['nameAm'] = 'tooLong';
        }

        $category = self::str($values['category'] ?? '');
        if (! Categories::isServiceCategory($category)) {
            $fields['category'] = 'invalidCategory';
        }

        $currentPrice = trim(self::str($values['currentPrice'] ?? ''));
        if ($currentPrice === '') {
            $fields['currentPrice'] = 'required';
        } elseif (preg_match(self::PRICE_RE, $currentPrice) !== 1 || ! Money::isMoneyInRange($currentPrice)) {
            $fields['currentPrice'] = 'invalidPrice';
        }

        $publicDescriptionEn = self::nullableTrimmed($values['publicDescriptionEn'] ?? '');
        if ($publicDescriptionEn !== null && mb_strlen($publicDescriptionEn) > self::MAX_PUBLIC_DESCRIPTION) {
            $fields['publicDescriptionEn'] = 'tooLong';
        }

        $publicDescriptionAm = self::nullableTrimmed($values['publicDescriptionAm'] ?? '');
        if ($publicDescriptionAm !== null && mb_strlen($publicDescriptionAm) > self::MAX_PUBLIC_DESCRIPTION) {
            $fields['publicDescriptionAm'] = 'tooLong';
        }

        $receiptLabelEn = self::nullableTrimmed($values['receiptLabelEn'] ?? '');
        if ($receiptLabelEn !== null && mb_strlen($receiptLabelEn) > self::MAX_SERVICE_NAME) {
            $fields['receiptLabelEn'] = 'tooLong';
        }

        $receiptLabelAm = self::nullableTrimmed($values['receiptLabelAm'] ?? '');
        if ($receiptLabelAm !== null && mb_strlen($receiptLabelAm) > self::MAX_SERVICE_NAME) {
            $fields['receiptLabelAm'] = 'tooLong';
        }

        $imageUrl = self::nullableTrimmed($values['imageUrl'] ?? '');
        if ($imageUrl !== null && mb_strlen($imageUrl) > self::MAX_IMAGE_URL) {
            $fields['imageUrl'] = 'tooLong';
        }

        if ($image !== null) {
            if (self::imageExtension($image['mimeType']) === null) {
                $fields['image'] = 'imageInvalidType';
            } elseif ($image['size'] > self::MAX_SERVICE_IMAGE_BYTES) {
                $fields['image'] = 'imageTooLarge';
            }
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'nameEn' => $nameEn,
                'nameAm' => $nameAm === '' ? null : $nameAm,
                'category' => $category,
                'currentPrice' => $currentPrice,
                // unchecked / absent → false (legacy: value === 'true')
                'isPublic' => self::str($values['isPublic'] ?? '') === 'true',
                'publicDescriptionEn' => $publicDescriptionEn,
                'publicDescriptionAm' => $publicDescriptionAm,
                'receiptLabelEn' => $receiptLabelEn,
                'receiptLabelAm' => $receiptLabelAm,
                'displayOrder' => self::displayOrder($values['displayOrder'] ?? ''),
                'imageUrl' => $imageUrl,
                'hasImage' => $image !== null,
            ],
            'fields' => [],
        ];
    }

    /**
     * `Number()` finite ∧ ≥0 → floor, else 0 (legacy displayOrder transform).
     */
    public static function displayOrder(mixed $value): int
    {
        $raw = trim(self::str($value));

        if ($raw === '' || ! is_numeric($raw)) {
            return 0;
        }

        $parsed = (float) $raw;

        if (! is_finite($parsed) || $parsed < 0) {
            return 0;
        }

        return (int) floor($parsed);
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /** Trim, then `|| null` (legacy `.trim().max(…).transform(v => v || null)`). */
    private static function nullableTrimmed(mixed $value): ?string
    {
        $trimmed = trim(self::str($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
