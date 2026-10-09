import { describe, expect, it } from 'vitest';
import {
  addMoney,
  averageMoney,
  formatMoney,
  isMoneyInRange,
  lineTotal,
  MAX_MONEY,
  money,
  multiplyMoney,
  percentChange,
  quantity,
  sumLineTotals,
  toDecimalString,
} from './money';

describe('money', () => {
  it('normalizes to 2 decimal places', () => {
    expect(toDecimalString(money('10.5'))).toBe('10.50');
    expect(toDecimalString(money('0'))).toBe('0.00');
    expect(toDecimalString(money('1234.567'))).toBe('1234.57');
  });

  it('adds without float errors', () => {
    expect(toDecimalString(addMoney('0.10', '0.20'))).toBe('0.30');
    expect(toDecimalString(addMoney('19.99', '0.01', '100'))).toBe('120.00');
    expect(toDecimalString(addMoney())).toBe('0.00');
  });

  it('multiplies unit price by quantity', () => {
    expect(toDecimalString(multiplyMoney('199.99', 3))).toBe('599.97');
    expect(toDecimalString(lineTotal('50.00', 2))).toBe('100.00');
  });

  it('sums sale line totals', () => {
    expect(
      toDecimalString(
        sumLineTotals([
          { unitPrice: '100.00', quantity: 2 },
          { unitPrice: '33.33', quantity: 3 },
        ]),
      ),
    ).toBe('299.99');
  });

  it('rejects fractional quantities', () => {
    expect(() => quantity(1.5)).toThrow();
    expect(() => quantity('2.5')).toThrow();
  });

  it('formats with the clinic currency (ETB default)', () => {
    const rendered = formatMoney('1234.5');
    expect(rendered).toContain('ETB');
    expect(rendered).toContain('1,234.50');
  });

  it('rounds half-up at the third decimal', () => {
    expect(toDecimalString(money('0.005'))).toBe('0.01');
    expect(toDecimalString(money('0.004'))).toBe('0.00');
    expect(toDecimalString(multiplyMoney('0.005', 1))).toBe('0.01');
    expect(toDecimalString(multiplyMoney('1.005', 1))).toBe('1.01');
    expect(toDecimalString(addMoney('0.005', '0.005'))).toBe('0.01');
  });

  it('handles large NUMERIC(12,2) values exactly', () => {
    expect(toDecimalString(addMoney('9999999999.99', '0'))).toBe('9999999999.99');
    expect(toDecimalString(money('9999999999.994'))).toBe('9999999999.99');
    expect(isMoneyInRange('9999999999.99')).toBe(true);
    expect(isMoneyInRange(MAX_MONEY)).toBe(true);
    expect(isMoneyInRange('10000000000.00')).toBe(false);
    expect(isMoneyInRange('-1')).toBe(false);
    expect(isMoneyInRange('abc')).toBe(false);
  });

  it('averages money with half-up rounding', () => {
    expect(toDecimalString(averageMoney('100.00', 4))).toBe('25.00');
    expect(toDecimalString(averageMoney('100.00', 3))).toBe('33.33');
    expect(toDecimalString(averageMoney('0.05', 2))).toBe('0.03');
    expect(toDecimalString(averageMoney('500.00', 0))).toBe('0.00');
  });

  it('computes percentage change, null when previous is zero', () => {
    expect(percentChange('150.00', '100.00')).toBe(50);
    expect(percentChange('50.00', '100.00')).toBe(-50);
    expect(percentChange('100.00', '0')).toBeNull();
    expect(percentChange('133.33', '100.00')).toBe(33.3);
  });
});
