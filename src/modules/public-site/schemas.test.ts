import { describe, expect, it } from 'vitest';
import { ValidationError, validate } from '@/lib/errors';
import { bookingFormSchema, feedbackFormSchema, isHoneypotTripped } from './schemas';

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

const feedbackBase = {
  name: 'Tigist',
  message: 'The staff were very helpful.',
  language: 'en' as const,
  consent: true,
  website: '',
};

function parseFeedback(overrides: Record<string, unknown>) {
  return validate(feedbackFormSchema, { ...feedbackBase, ...overrides });
}

describe('feedbackFormSchema', () => {
  it('accepts a minimal submission and normalises optionals to null', () => {
    const values = parseFeedback({});
    expect(values.email).toBeNull();
    expect(values.rating).toBeNull();
    expect(values.name).toBe('Tigist');
  });

  it('accepts a form-string rating and normalises the email', () => {
    expect(parseFeedback({ rating: '5' }).rating).toBe(5);
    expect(parseFeedback({ rating: 4 }).rating).toBe(4);
    expect(parseFeedback({ rating: '' }).rating).toBeNull();
    expect(parseFeedback({ email: '  PATIENT@Example.COM ' }).email).toBe('patient@example.com');
  });

  it('rejects an out-of-range rating, a bad email and a blank message', () => {
    expect(() => parseFeedback({ rating: '6' })).toThrow(ValidationError);
    expect(() => parseFeedback({ rating: 0 })).toThrow(ValidationError);
    expect(() => parseFeedback({ rating: 'great' })).toThrow(ValidationError);
    expect(() => parseFeedback({ email: 'not-an-email' })).toThrow(ValidationError);
    expect(() => parseFeedback({ message: '' })).toThrow(ValidationError);
  });

  it('requires consent and caps the message at 1000 characters', () => {
    expect(() => parseFeedback({ consent: false })).toThrow(ValidationError);
    expect(() => parseFeedback({ message: 'x'.repeat(1001) })).toThrow(ValidationError);
    expect(parseFeedback({ message: 'x'.repeat(1000) }).message).toHaveLength(1000);
  });

  it('shares the booking honeypot', () => {
    expect(isHoneypotTripped(parseFeedback({}))).toBe(false);
    expect(isHoneypotTripped({ website: 'http://spam' })).toBe(true);
    // The schema itself also rejects a filled honeypot before any DB write.
    expect(() => parseFeedback({ website: 'http://spam' })).toThrow(ValidationError);
  });
});
