<?php

declare(strict_types=1);

namespace App\Support\Sales;

/**
 * Sale + void-reason validation, ported from src/modules/sales/schemas.ts.
 *
 * Field errors are `['field' => '<i18n key>']`; the view renders
 * `validation.<key>`. Zod's `z.number().int()` on the client sends numbers, but
 * the HTML form posts the lines as a JSON string, so qty arrives as whatever
 * JSON contains — the same coercion rules are applied here.
 */
final class SaleValidation
{
    /**
     * Validate the sale form. `lines` is the parsed JSON value (callers parse
     * the string and pass `[]` on failure, mirroring legacy).
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function saleForm(array $values): array
    {
        $fields = [];

        $patientId = self::str($values['patientId'] ?? '');
        if (mb_strlen($patientId) > 64) {
            $fields['patientId'] = 'tooLong';
        }

        $doctorId = self::str($values['doctorId'] ?? '');
        if (mb_strlen($doctorId) > 64) {
            $fields['doctorId'] = 'tooLong';
        }

        $paymentMethod = self::str($values['paymentMethod'] ?? '');
        if (! Constants::isPaymentMethod($paymentMethod)) {
            $fields['paymentMethod'] = 'invalidPaymentMethod';
        }

        $rawLines = is_array($values['lines'] ?? null) ? $values['lines'] : [];
        $lines = [];

        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $serviceId = self::str($line['serviceId'] ?? '');
            $serviceIdOk = mb_strlen($serviceId) >= 1 && mb_strlen($serviceId) <= 64;

            $qtyRaw = $line['qty'] ?? null;
            $qtyInt = self::toInt($qtyRaw);

            if (! $serviceIdOk) {
                $fields['lines'] = 'required';
            } elseif ($qtyInt === null) {
                $fields['lines'] = 'invalidQuantity';
            } elseif ($qtyInt < 1) {
                $fields['lines'] = 'minQuantity';
            } elseif ($qtyInt > Constants::MAX_QTY) {
                $fields['lines'] = 'tooMany';
            } else {
                $lines[] = ['serviceId' => $serviceId, 'qty' => $qtyInt];
            }
        }

        if (! isset($fields['lines'])) {
            if (count($lines) < 1) {
                $fields['lines'] = 'noLines';
            } elseif (count($lines) > Constants::MAX_SALE_LINES) {
                $fields['lines'] = 'tooManyLines';
            }
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'patientId' => $patientId,
                'doctorId' => $doctorId,
                'paymentMethod' => $paymentMethod,
                'lines' => $lines,
                'walkIn' => self::str($values['walkIn'] ?? '') === 'true',
            ],
            'fields' => [],
        ];
    }

    /**
     * `voidReasonSchema`: trim, min 3 (`required`), max 300 (`tooLong`).
     *
     * @param  array<string, mixed>  $values
     * @return array{value: string|null, fields: array<string, string>}
     */
    public static function voidReason(array $values): array
    {
        $reason = trim(self::str($values['reason'] ?? ''));

        if ($reason === '' || mb_strlen($reason) < 3) {
            return ['value' => null, 'fields' => ['reason' => 'required']];
        }

        if (mb_strlen($reason) > Constants::MAX_REASON) {
            return ['value' => null, 'fields' => ['reason' => 'tooLong']];
        }

        return ['value' => $reason, 'fields' => []];
    }

    /**
     * Parses the `lines` form field (a JSON string). Parse failure and
     * non-arrays both become `[]` → the schema then reports `noLines`.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseLines(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        // Legacy: `Array.isArray(parsed) ? parsed : null` — a JSON object is not
        // a list and must become [].
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** int coercion mirroring `Number.isInteger` (JSON numbers only). */
    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
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
}
