import { describe, expect, it } from 'vitest';
import {
  isLocked,
  LOCK_MINUTES,
  MAX_FAILED_LOGINS,
  nextLoginStateAfterFailure,
  stateAfterLoginSuccess,
} from './login-policy';

const NOW = new Date('2026-10-08T10:00:00.000Z');

describe('login-policy (lockout)', () => {
  it('locks the account on the 5th consecutive failure for 15 minutes', () => {
    let count = 0;
    let state = { failedLoginCount: 0, lockedUntil: null as Date | null };
    for (let attempt = 1; attempt <= MAX_FAILED_LOGINS; attempt++) {
      state = nextLoginStateAfterFailure(state.failedLoginCount, NOW);
      count = state.failedLoginCount;
    }
    expect(count).toBe(5);
    expect(state.lockedUntil).not.toBeNull();
    const expected = new Date(NOW.getTime() + LOCK_MINUTES * 60_000);
    expect(state.lockedUntil?.getTime()).toBe(expected.getTime());
  });

  it('does not lock before the 5th failure', () => {
    for (let failures = 1; failures < MAX_FAILED_LOGINS; failures++) {
      const state = nextLoginStateAfterFailure(failures - 1, NOW);
      expect(state.lockedUntil).toBeNull();
      expect(state.failedLoginCount).toBe(failures);
    }
  });

  it('isLocked is true only inside the lock window', () => {
    const lockedUntil = new Date(NOW.getTime() + LOCK_MINUTES * 60_000);
    expect(isLocked(lockedUntil, NOW)).toBe(true);
    expect(isLocked(lockedUntil, new Date(lockedUntil.getTime() + 1))).toBe(false);
    expect(isLocked(null, NOW)).toBe(false);
    expect(isLocked(undefined, NOW)).toBe(false);
  });

  it('a successful login resets the counter and clears the lock', () => {
    const state = stateAfterLoginSuccess(NOW);
    expect(state.failedLoginCount).toBe(0);
    expect(state.lockedUntil).toBeNull();
    expect(state.lastLoginAt).toEqual(NOW);
    expect(isLocked(state.lockedUntil, NOW)).toBe(false);
  });
});
