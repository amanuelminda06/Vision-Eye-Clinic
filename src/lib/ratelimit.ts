/**
 * In-memory sliding-window rate limiter (single-process — fine for this
 * self-hosted deployment). Usable for the login endpoint (default limits) and
 * the public booking endpoint (tighter, per-IP limits).
 *
 * NOTE: process-local. Swap for Redis if the app ever runs multi-instance.
 */

export const RATE_LIMIT_MAX = 30;
export const RATE_LIMIT_WINDOW_MS = 5 * 60_000;

export type RateLimitOptions = {
  max?: number;
  windowMs?: number;
  now?: number;
};

type Bucket = { count: number; resetAt: number };

const buckets = new Map<string, Bucket>();

/** Returns true when the attempt is allowed (and counts it), false when blocked. */
export function checkRateLimit(key: string, options: RateLimitOptions = {}): boolean {
  const max = options.max ?? RATE_LIMIT_MAX;
  const windowMs = options.windowMs ?? RATE_LIMIT_WINDOW_MS;
  const now = options.now ?? Date.now();

  const bucket = buckets.get(key);

  if (!bucket || bucket.resetAt <= now) {
    if (buckets.size > 5_000) {
      for (const [k, b] of buckets) {
        if (b.resetAt <= now) buckets.delete(k);
      }
    }
    buckets.set(key, { count: 1, resetAt: now + windowMs });
    return true;
  }

  if (bucket.count >= max) return false;
  bucket.count += 1;
  return true;
}

/** Test helper. */
export function resetRateLimits(): void {
  buckets.clear();
}
