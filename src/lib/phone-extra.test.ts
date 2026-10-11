import { describe, it, expect } from 'vitest';
import { formatPhoneDisplay, phoneSearchVariants } from './phone';

describe('formatPhoneDisplay', () => {
  it('renders E.164 Ethiopian numbers in national format', () => {
    expect(formatPhoneDisplay('+251912345678')).toBe('0912345678');
    expect(formatPhoneDisplay('+251754321098')).toBe('0754321098');
  });

  it('leaves other E.164 numbers untouched', () => {
    expect(formatPhoneDisplay('+971501234567')).toBe('+971501234567');
  });
});

describe('phoneSearchVariants', () => {
  it('returns null when there are no digits', () => {
    expect(phoneSearchVariants('')).toBeNull();
    expect(phoneSearchVariants('ab cd')).toBeNull();
  });

  it('returns digit-only fragment variants', () => {
    const variants = phoneSearchVariants('0912 34')!;
    expect(variants).toContain('091234');
    expect(variants).toContain('+25191234');
    expect(variants).toContain('91234');
  });

  it('expands a known-digit fragment into E.164 candidates', () => {
    const variants = phoneSearchVariants('0912')!;
    expect(variants).toEqual(expect.arrayContaining(['0912', '+251912', '912']));
  });

  it('handles partial E.164 input', () => {
    const variants = phoneSearchVariants('251912345')!;
    expect(variants).toContain('+251912345');
    expect(variants).toContain('251912345');
  });
});