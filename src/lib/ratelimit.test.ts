import { describe, it, expect, beforeEach } from 'vitest';
import {
  checkRateLimit,
  resetRateLimits,
  RATE_LIMIT_MAX,
} from './ratelimit';

beforeEach(resetRateLimits);

describe('in-memory sliding-window rate limiter', () => {
  it('allows requests up to the default limit then blocks', () => {
    const now = 1_000_000;
    for (let i = 0; i < RATE_LIMIT_MAX; i++) {
      expect(checkRateLimit('ip-a', { now })).toBe(true);
    }
    expect(checkRateLimit('ip-a', { now })).toBe(false);
    // a different key is unaffected
    expect(checkRateLimit('ip-b', { now })).toBe(true);
  });

  it('allows a custom limit/window', () => {
    expect(checkRateLimit('k', { max: 2, windowMs: 60_000, now: 0 })).toBe(true);
    expect(checkRateLimit('k', { max: 2, windowMs: 60_000, now: 1 })).toBe(true);
    expect(checkRateLimit('k', { max: 2, windowMs: 60_000, now: 2 })).toBe(false);
  });

  it('resets a bucket after its window expires', () => {
    expect(checkRateLimit('k', { max: 1, windowMs: 10_000, now: 0 })).toBe(true);
    expect(checkRateLimit('k', { max: 1, windowMs: 10_000, now: 9_999 })).toBe(false);
    expect(checkRateLimit('k', { max: 1, windowMs: 10_000, now: 10_000 })).toBe(true);
    expect(checkRateLimit('k', { max: 1, windowMs: 10_000, now: 10_001 })).toBe(false);
  });

  it('prunes expired buckets when the map grows large', () => {
    const now = 5_000;
    for (let i = 0; i < 5_100; i++) {
      checkRateLimit(`p-${i}`, { max: 1, windowMs: 10_000, now });
    }
    // expired buckets are removed next time the map exceeds the cap
    resetRateLimits();
    for (let i = 0; i < 5_100; i++) {
      checkRateLimit(`p-${i}`, { max: 1, windowMs: 10_000, now });
    }
    // A previously blocked, now-expired key is allowed again after pruning.
    expect(checkRateLimit('p-0', { max: 1, windowMs: 10_000, now: now + 11_000 })).toBe(true);
  });

  it('resetRateLimits clears all buckets', () => {
    checkRateLimit('r', { max: 1, now: 0 });
    resetRateLimits();
    expect(checkRateLimit('r', { max: 1, now: 0 })).toBe(true);
  });
});