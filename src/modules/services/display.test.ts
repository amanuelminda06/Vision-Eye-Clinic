import { describe, expect, it } from 'vitest';
import { receiptLabelIn, serviceNameIn } from './display';

describe('serviceNameIn', () => {
  const base = { name: 'Legacy', nameEn: 'English', nameAm: 'አማርኛ' } as const;

  it.each([
    ['en', 'English'],
    ['am', 'አማርኛ'],
  ])('uses the %s name when present', (locale, expected) => {
    expect(serviceNameIn(locale, base)).toBe(expected);
  });

  it('falls back to nameEn without an Amharic name', () => {
    expect(serviceNameIn('am', { ...base, nameAm: null })).toBe('English');
  });

  it('falls back to name when nameEn is missing', () => {
    expect(serviceNameIn('en', { name: 'Legacy', nameEn: null, nameAm: null })).toBe('Legacy');
  });
});

describe('receiptLabelIn', () => {
  const labels = { receiptLabelEn: 'Check-up', receiptLabelAm: 'ክትትል', fallback: 'Long name' } as const;

  it.each([
    ['en', 'Check-up'],
    ['am', 'ክትትል'],
  ])('uses the %s label when present', (locale, expected) => {
    expect(receiptLabelIn(locale, labels)).toBe(expected);
  });

  it('falls back to the other language label for am', () => {
    expect(receiptLabelIn('am', { receiptLabelEn: 'Check-up', receiptLabelAm: null, fallback: 'X' })).toBe(
      'Check-up',
    );
  });

  it('falls back to the other language label for en', () => {
    expect(receiptLabelIn('en', { receiptLabelEn: null, receiptLabelAm: 'ክትትል', fallback: 'X' })).toBe(
      'ክትትል',
    );
  });

  it('falls back to the full service name when no label exists', () => {
    expect(
      receiptLabelIn('en', { receiptLabelEn: null, receiptLabelAm: null, fallback: 'Long name' }),
    ).toBe('Long name');
    expect(
      receiptLabelIn('am', { receiptLabelEn: null, receiptLabelAm: null, fallback: 'Long name' }),
    ).toBe('Long name');
  });
});