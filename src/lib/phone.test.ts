import { describe, expect, it } from 'vitest';
import { isValidPhone, normalizePhone, parsePhone, phoneSearchVariants } from './phone';

describe('normalizePhone', () => {
  it('accepts all supported Ethiopian mobile formats', () => {
    expect(normalizePhone('0912345678')).toBe('+251912345678');
    expect(normalizePhone('912345678')).toBe('+251912345678');
    expect(normalizePhone('+251912345678')).toBe('+251912345678');
    expect(normalizePhone(' 0912 345 678 ')).toBe('+251912345678');
    expect(normalizePhone('0912-345-678')).toBe('+251912345678');
    expect(normalizePhone('+251 912 345 678')).toBe('+251912345678');
    expect(normalizePhone('(0912) 345.678')).toBe('+251912345678');
  });

  it('accepts the 07 mobile prefix in every supported form', () => {
    expect(normalizePhone('0712345678')).toBe('+251712345678');
    expect(normalizePhone('712345678')).toBe('+251712345678');
    expect(normalizePhone('+251712345678')).toBe('+251712345678');
    expect(normalizePhone('0712-345-678')).toBe('+251712345678');
    expect(normalizePhone(' 07 12 34 56 78 ')).toBe('+251712345678');
  });

  it('rejects non-mobile or malformed numbers', () => {
    expect(normalizePhone('091234567')).toBeNull();
    expect(normalizePhone('09123456789')).toBeNull();
    expect(normalizePhone('071234567')).toBeNull();
    expect(normalizePhone('+251812345678')).toBeNull();
    expect(normalizePhone('+12025550123')).toBeNull();
    expect(normalizePhone('12345')).toBeNull();
    expect(normalizePhone('')).toBeNull();
    expect(normalizePhone('   ')).toBeNull();
    expect(normalizePhone('not-a-phone')).toBeNull();
    expect(normalizePhone('0912345678')).toBeTruthy();
    expect(normalizePhone('telephone')).toBeNull();
  });

  it('rejects non-string input', () => {
    expect(normalizePhone(null as unknown as string)).toBeNull();
    expect(normalizePhone(undefined as unknown as string)).toBeNull();
    expect(normalizePhone(912345678 as unknown as string)).toBeNull();
  });

  it('isValidPhone mirrors normalizePhone', () => {
    expect(isValidPhone('0912345678')).toBe(true);
    expect(isValidPhone('0712345678')).toBe(true);
    expect(isValidPhone('0812345678')).toBe(false);
  });
});

describe('parsePhone', () => {
  it('returns the normalized value on success', () => {
    expect(parsePhone('0912345678')).toEqual({ ok: true, value: '+251912345678' });
    expect(parsePhone('0712345678')).toEqual({ ok: true, value: '+251712345678' });
  });

  it('distinguishes empty input from invalid input', () => {
    expect(parsePhone('')).toEqual({ ok: false, error: 'required' });
    expect(parsePhone('   ')).toEqual({ ok: false, error: 'required' });
    expect(parsePhone('12345')).toEqual({ ok: false, error: 'invalidPhone' });
    expect(parsePhone('not-a-phone')).toEqual({ ok: false, error: 'invalidPhone' });
  });
});

describe('phoneSearchVariants', () => {
  it('produces fragment candidates for 09/07 input', () => {
    expect(phoneSearchVariants('0912 34')).toEqual(expect.arrayContaining(['91234', '+25191234']));
    expect(phoneSearchVariants('0712')).toEqual(expect.arrayContaining(['712', '+251712']));
    expect(phoneSearchVariants('+2519123')).toEqual(
      expect.arrayContaining(['2519123', '+2519123']),
    );
  });

  it('passes plain digit fragments through', () => {
    expect(phoneSearchVariants('912345')).toEqual(['912345']);
  });

  it('returns null when there are no digits', () => {
    expect(phoneSearchVariants('abc')).toBeNull();
    expect(phoneSearchVariants('')).toBeNull();
  });
});
