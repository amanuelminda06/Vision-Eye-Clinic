import { createHash } from 'node:crypto';

/**
 * Privacy-safe IP hashing (rule 8): the raw IP is never persisted — only a
 * sha-256 hex digest, suitable for abuse correlation on the public request
 * endpoint.
 */
export function hashClientIp(input: string | null | undefined): string {
  if (!input) return '';
  return createHash('sha256').update(input).digest('hex');
}
