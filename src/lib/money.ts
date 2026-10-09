import Decimal from 'decimal.js';

/**
 * Money helpers (rule 1): Decimal-only arithmetic, 2 decimal places,
 * NUMERIC(12,2) in the database. Never use JS floats for money.
 *
 * Input types are deliberately narrow — a bare JS `number` is only accepted
 * when it is an integer (quantities), never as a currency amount.
 */

export type MoneyInput = string | Decimal;

/** NUMERIC(12,2) upper bound — 10 integer digits + 2 decimals. */
export const MAX_MONEY = new Decimal('9999999999.99');
export const ZERO_MONEY = new Decimal('0.00');

export function money(value: MoneyInput): Decimal {
  return new Decimal(value).toDecimalPlaces(2);
}

/** True when `value` fits a NUMERIC(12,2) column (non-negative, 2 decimals). */
export function isMoneyInRange(value: MoneyInput): boolean {
  try {
    const amount = new Decimal(value);
    return amount.isFinite() && amount.gte(0) && amount.lte(MAX_MONEY);
  } catch {
    return false;
  }
}

/** Quantity is an integer, so a number input is safe here. */
export function quantity(value: number | string): Decimal {
  const d = new Decimal(value);
  if (!d.isInteger()) throw new Error('Quantity must be an integer');
  return d;
}

export function addMoney(...values: MoneyInput[]): Decimal {
  return values
    .reduce<Decimal>((sum, v) => sum.plus(new Decimal(v)), new Decimal(0))
    .toDecimalPlaces(2);
}

export function multiplyMoney(unitPrice: MoneyInput, qty: number | string): Decimal {
  return money(unitPrice).times(quantity(qty)).toDecimalPlaces(2);
}

export function lineTotal(unitPrice: MoneyInput, qty: number | string): Decimal {
  return multiplyMoney(unitPrice, qty);
}

export function sumLineTotals(
  lines: Array<{ unitPrice: MoneyInput; quantity: number | string }>,
): Decimal {
  return lines
    .reduce<Decimal>((sum, l) => sum.plus(multiplyMoney(l.unitPrice, l.quantity)), new Decimal(0))
    .toDecimalPlaces(2);
}

/** Persistable string form for Prisma Decimal columns. */
export function toDecimalString(value: MoneyInput): string {
  return money(value).toFixed(2);
}

/**
 * Average of money amounts (total / count), rounded half-up to 2 places.
 * Zero/absent count → 0.00 (empty periods never divide by zero).
 */
export function averageMoney(total: MoneyInput, count: number): Decimal {
  if (!Number.isFinite(count) || count <= 0) return ZERO_MONEY;
  return money(total).dividedBy(count).toDecimalPlaces(2);
}

/**
 * Percentage change from `previous` to `current`, rounded half-up to 1 decimal.
 * Returns null when the previous value is zero (no meaningful comparison).
 */
export function percentChange(current: MoneyInput, previous: MoneyInput): number | null {
  const before = money(previous);
  if (before.isZero()) return null;
  return money(current).minus(before).dividedBy(before).times(100).toDecimalPlaces(1).toNumber();
}

/** Display form — currency comes from settings (rule 1), default ETB. */
export function formatMoney(value: MoneyInput, currency = 'ETB', locale = 'en-ET'): string {
  const amount = money(value);
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    currencyDisplay: 'code',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount.toNumber());
}
