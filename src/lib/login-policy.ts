/**
 * Account-lockout policy (pure — unit tested).
 * 5 failed attempts lock the account for 15 minutes; success resets counters.
 */

export const MAX_FAILED_LOGINS = 5;
export const LOCK_MINUTES = 15;

export function isLocked(lockedUntil: Date | null | undefined, now: Date): boolean {
  return !!lockedUntil && lockedUntil.getTime() > now.getTime();
}

export type LoginFailureState = {
  failedLoginCount: number;
  lockedUntil: Date | null;
};

/**
 * State to persist after a failed password attempt.
 * The 5th failure sets lockedUntil = now + 15 minutes.
 * Does nothing useful for an already-locked account (call isLocked first).
 */
export function nextLoginStateAfterFailure(failedCount: number, now: Date): LoginFailureState {
  const count = failedCount + 1;
  const lockedUntil =
    count >= MAX_FAILED_LOGINS ? new Date(now.getTime() + LOCK_MINUTES * 60_000) : null;
  return { failedLoginCount: count, lockedUntil };
}

/** State to persist after a successful login. */
export function stateAfterLoginSuccess(now: Date): {
  failedLoginCount: number;
  lockedUntil: null;
  lastLoginAt: Date;
} {
  return { failedLoginCount: 0, lockedUntil: null, lastLoginAt: now };
}
