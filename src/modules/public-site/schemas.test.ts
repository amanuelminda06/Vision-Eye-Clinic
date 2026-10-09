import { describe, expect, it } from 'vitest';
import { ValidationError, validate } from '@/lib/errors';
import { bookingFormSchema, isHoneypotTripped } from './schemas';

const base = {
  name: 'Abebe',
  phone: '0911223344',
  preferredDate: '2026-11-01',
  preferredTimeWindow: 'morning' as const,
  language: 'en' as const,
  consent: true,
  website: '',
};

function parse(overrides: Record<string, unknown>) {
  return validate(bookingFormSchema, { ...base, ...overrides });
}

describe('bookingFormSchema', () => {
  it('accepts a valid request and normalises optionals to null', () => {
    const values = parse({});
    expect(values.serviceInterest).toBeNull();
    expect(values.message).toBeNull();
  });

  it('requires consent', () => {
    expect(() => parse({ consent: false })).toThrow(ValidationError);
  });

  it('rejects a bad date and an unknown time window', () => {
    expect(() => parse({ preferredDate: '01-11-2026' })).toThrow(ValidationError);
    expect(() => parse({ preferredTimeWindow: 'midnight' })).toThrow(ValidationError);
  });

  it('caps the message at 500 characters', () => {
    expect(() => parse({ message: 'x'.repeat(501) })).toThrow(ValidationError);
    expect(parse({ message: 'x'.repeat(500) }).message).toHaveLength(500);
  });

  it('detects a filled honeypot', () => {
    expect(isHoneypotTripped({ website: '' })).toBe(false);
    expect(isHoneypotTripped({ website: 'http://spam' })).toBe(true);
  });
});
