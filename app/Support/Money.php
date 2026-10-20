<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal money helpers, ported from src/lib/money.ts (rule 1).
 *
 * Decimal-only arithmetic on bcmath, 2 decimal places, HALF-UP rounding
 * (decimal.js toDecimalPlaces(2) default ROUND_HALF_UP). Never floats for
 * money: pass plain decimal strings, get decimal strings back. NUMERIC(12,2)
 * in the database (MAX_MONEY = 10 integer digits + 2 decimals).
 */
final class Money
{
    public const MAX_MONEY = '9999999999.99';

    public const ZERO_MONEY = '0.00';

    /** Intermediate precision for divisions (decimal.js uses 20 significant digits). */
    private const DIV_SCALE = 20;

    /** money(v) — normalize to 2 dp, half-up ('10.5' → '10.50', '1234.567' → '1234.57'). */
    public static function money(int|float|string $value): string
    {
        return self::roundHalfUp(self::canonical($value), 2);
    }

    /** toDecimalString(v) — the only persistable/serialized form (money(v).toFixed(2)). */
    public static function toDecimalString(int|float|string $value): string
    {
        return self::money($value);
    }

    /** isMoneyInRange(v) = finite ∧ ≥ 0 ∧ ≤ MAX ('abc' → false, never throws). */
    public static function isMoneyInRange(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return false;
        }

        if (is_float($value) && ! is_finite($value)) {
            return false;
        }

        try {
            $amount = self::canonical($value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return bccomp($amount, '0', self::scaleOf($amount)) >= 0
            && bccomp($amount, self::MAX_MONEY, max(self::scaleOf($amount), 2)) <= 0;
    }

    /**
     * quantity(v) — integers only ('2.0' ok), else throws
     * "Quantity must be an integer" (legacy quantity()).
     */
    public static function quantity(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new InvalidArgumentException('Quantity must be an integer');
            }

            return (string) (int) $value;
        }

        $canonical = self::canonical($value);

        if (str_contains($canonical, '.')) {
            throw new InvalidArgumentException('Quantity must be an integer');
        }

        return $canonical;
    }

    /** addMoney(...values) — exact sum then 2 dp half-up; no values → '0.00'. */
    public static function addMoney(int|float|string ...$values): string
    {
        $scale = 0;
        $canonicals = [];

        foreach ($values as $value) {
            $canonical = self::canonical($value);
            $canonicals[] = $canonical;
            $scale = max($scale, self::scaleOf($canonical));
        }

        $sum = '0';
        foreach ($canonicals as $canonical) {
            $sum = bcadd($sum, $canonical, $scale);
        }

        return self::roundHalfUp($sum, 2);
    }

    /** multiplyMoney(unitPrice, qty) — money(unit) × quantity(qty) exact, then 2 dp half-up. */
    public static function multiplyMoney(int|float|string $unitPrice, int|float|string $qty): string
    {
        $quantity = self::quantity($qty);
        $unit = self::canonical($unitPrice);

        // Exact product: scale = sum of operand scales (no rounding yet).
        $product = bcmul($unit, $quantity, self::scaleOf($unit) + self::scaleOf($quantity));

        return self::roundHalfUp($product, 2);
    }

    /** lineTotal(unitPrice, qty) — alias of multiplyMoney (legacy lineTotal). */
    public static function lineTotal(int|float|string $unitPrice, int|float|string $qty): string
    {
        return self::multiplyMoney($unitPrice, $qty);
    }

    /**
     * sumLineTotals([{unitPrice, quantity}, ...]) — Σ multiplyMoney, 2 dp.
     *
     * @param  list<array{unitPrice: int|float|string, quantity: int|float|string}>  $lines
     */
    public static function sumLineTotals(array $lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $total = bcadd($total, self::multiplyMoney($line['unitPrice'], $line['quantity']), 2);
        }

        return self::roundHalfUp($total, 2);
    }

    /** averageMoney(total, count): count ≤ 0 / non-finite → '0.00'; else total/count 2 dp half-up. */
    public static function averageMoney(int|float|string $total, int|float $count): string
    {
        if (! is_numeric($count) || ! is_finite((float) $count) || $count <= 0) {
            return '0.00';
        }

        $quotient = bcdiv(self::canonical($total), (string) $count, self::DIV_SCALE);

        return self::roundHalfUp($quotient, 2);
    }

    /**
     * percentChange(current, previous): previous zero → null; else
     * ((cur − prev) / prev) × 100 rounded half-up to 1 dp. (legacy returns a number)
     */
    public static function percentChange(int|float|string $current, int|float|string $previous): ?float
    {
        $before = self::money($previous);
        if (bccomp($before, '0', 2) === 0) {
            return null;
        }

        $now = self::money($current);
        $difference = bcsub($now, $before, 2);
        $ratio = bcdiv($difference, $before, self::DIV_SCALE);
        $percent = bcmul($ratio, '100', self::DIV_SCALE);

        return (float) self::roundHalfUp($percent, 1);
    }

    /**
     * formatMoney(v, currency='ETB', locale='en-ET') — Intl currency-code rendering
     * ("ETB 1,234.50"). The locale parameter is kept for API parity; grouping follows
     * the en-ET convention (comma thousands separators) because no caller passes a
     * non-default locale (and the server has no intl extension guarantee).
     */
    public static function formatMoney(int|float|string $value, string $currency = 'ETB', string $locale = 'en-ET'): string
    {
        $amount = self::money($value);

        $negative = str_starts_with($amount, '-');
        if ($negative) {
            $amount = substr($amount, 1);
        }

        [$whole, $cents] = explode('.', $amount, 2);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($negative ? '-' : '').$currency.' '.$whole.'.'.$cents;
    }

    /**
     * Canonical plain decimal: validates, drops '+', leading/trailing zeros,
     * keeps the sign only for non-zero values. Throws on anything else.
     */
    private static function canonical(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Invalid money value');
            }

            // Fixed notation with enough precision, then trim fraction zeros.
            $value = sprintf('%.14F', $value);
            if (str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
        } else {
            $value = (string) $value;
        }

        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid money value');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = str_contains($value, '.')
            ? explode('.', $value, 2)
            : [$value, ''];

        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }

        $fraction = rtrim($fraction, '0');
        $canonical = $fraction === '' ? $whole : $whole.'.'.$fraction;

        return $negative && $canonical !== '0' ? '-'.$canonical : $canonical;
    }

    /** Decimal places of a canonical value. */
    private static function scaleOf(string $canonical): int
    {
        return str_contains($canonical, '.') ? strlen(substr($canonical, strrpos($canonical, '.') + 1)) : 0;
    }

    /**
     * Half-up rounding of a canonical decimal string (ties away from zero,
     * matching decimal.js ROUND_HALF_UP). Exact — no floats involved.
     */
    private static function roundHalfUp(string $canonical, int $scale): string
    {
        $negative = str_starts_with($canonical, '-');
        $value = $negative ? substr($canonical, 1) : $canonical;

        if (! str_contains($value, '.')) {
            $value .= '.';
        }

        [$whole, $fraction] = explode('.', $value, 2);
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, $scale + 1, '0');

        $rounded = $whole.(substr($fraction, 0, $scale) === '' ? '' : '.'.substr($fraction, 0, $scale));

        if ($fraction[$scale] >= '5') {
            $increment = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
            $rounded = bcadd($rounded, $increment, $scale);
        }

        if ($negative && bccomp($rounded, '0', $scale) !== 0) {
            $rounded = '-'.$rounded;
        }

        return $rounded;
    }
}
