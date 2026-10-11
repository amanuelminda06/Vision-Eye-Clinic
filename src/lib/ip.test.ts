import { describe, it, expect } from 'vitest';
import { createHash } from 'node:crypto';
import { hashClientIp } from './ip';

describe('hashClientIp', () => {
  it('returns a sha-256 hex digest (64 chars) for a raw IP', () => {
    const out = hashClientIp('196.188.12.34');
    expect(out).toMatch(/^[0-9a-f]{64}$/);
    expect(out).toBe(createHash('sha256').update('196.188.12.34').digest('hex'));
  });

  it('is deterministic for the same input', () => {
    expect(hashClientIp('1.2.3.4')).toBe(hashClientIp('1.2.3.4'));
  });

  it('handles IPv6 and proxied addresses', () => {
    expect(hashClientIp('::ffff:192.0.2.1')).toMatch(/^[0-9a-f]{64}$/);
  });

  it('returns empty string for null/undefined/empty input and never throws', () => {
    expect(hashClientIp(null)).toBe('');
    expect(hashClientIp(undefined)).toBe('');
    expect(hashClientIp('')).toBe('');
  });
});