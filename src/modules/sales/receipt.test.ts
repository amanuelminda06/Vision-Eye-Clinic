import { describe, expect, it } from 'vitest';
import { formatReceiptNumber, receiptYear, RECEIPT_PAD } from './receipt';

const TZ = 'Africa/Addis_Ababa';

describe('formatReceiptNumber', () => {
  it('pads to 6 digits', () => {
    expect(RECEIPT_PAD).toBe(6);
    expect(formatReceiptNumber(2026, 1)).toBe('2026-000001');
    expect(formatReceiptNumber(2026, 123)).toBe('2026-000123');
    expect(formatReceiptNumber(2026, 999999)).toBe('2026-999999');
  });

  it('rejects invalid values', () => {
    expect(() => formatReceiptNumber(2026, 0)).toThrow();
    expect(() => formatReceiptNumber(2026.5, 1)).toThrow();
    expect(() => formatReceiptNumber(2026, 1.5)).toThrow();
  });
});

describe('receiptYear', () => {
  it('uses the clinic timezone (23:30 Addis is still the same year)', () => {
    expect(receiptYear(new Date('2026-12-31T20:30:00.000Z'), TZ)).toBe(2026);
    expect(receiptYear(new Date('2026-12-31T21:30:00.000Z'), TZ)).toBe(2027);
  });
});
