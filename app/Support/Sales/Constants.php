<?php

declare(strict_types=1);

namespace App\Support\Sales;

/**
 * Fixed sales constants, ported from src/modules/sales/constants.ts.
 */
final class Constants
{
    /** @var list<string> */
    public const PAYMENT_METHODS = ['CASH', 'MOBILE_MONEY', 'BANK', 'CARD', 'OTHER'];

    /** `__('sales.<key>')` for each payment method. */
    public const PAYMENT_LABEL_KEYS = [
        'CASH' => 'payCash',
        'MOBILE_MONEY' => 'payMobileMoney',
        'BANK' => 'payBank',
        'CARD' => 'payCard',
        'OTHER' => 'payOther',
    ];

    /** @var list<string> */
    public const SALE_STATUSES = ['DRAFT', 'CONFIRMED', 'VOIDED'];

    /** `__('sales.<key>')` for each status. */
    public const STATUS_LABEL_KEYS = [
        'DRAFT' => 'statusDraft',
        'CONFIRMED' => 'statusConfirmed',
        'VOIDED' => 'statusVoided',
    ];

    public const SALES_PAGE_SIZE = 20;

    public const SALES_EXPORT_CAP = 5000;

    public const MAX_SALE_LINES = 50;

    public const MAX_QTY = 999;

    public const MAX_REASON = 300;

    public const MAX_PATIENT_QUERY = 100;

    public static function isPaymentMethod(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::PAYMENT_METHODS, true);
    }

    public static function isSaleStatus(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::SALE_STATUSES, true);
    }

    /** The label key for a payment method, or the raw value when unknown. */
    public static function paymentLabelKey(string $value): string
    {
        return self::PAYMENT_LABEL_KEYS[$value] ?? $value;
    }

    /** The label key for a status, or the raw value when unknown. */
    public static function statusLabelKey(string $value): string
    {
        return self::STATUS_LABEL_KEYS[$value] ?? $value;
    }
}
