import { env } from '@/env';

/**
 * Cloudflare Turnstile verification (OPTIONAL, disabled by default).
 *
 * When `TURNSTILE_SECRET_KEY` is unset the check is a no-op (returns true), so
 * the booking form works with just the honeypot + rate limit. To enable, set
 * `TURNSTILE_SECRET_KEY` (and `NEXT_PUBLIC_TURNSTILE_SITE_KEY`), and widen the
 * CSP in next.config.ts to allow challenges.cloudflare.com.
 */
export function turnstileEnabled(): boolean {
  return Boolean(env.TURNSTILE_SECRET_KEY);
}

export async function verifyTurnstile(
  token: string | undefined,
  remoteIp: string | null,
): Promise<boolean> {
  const secret = env.TURNSTILE_SECRET_KEY;
  if (!secret) return true;
  if (!token) return false;

  try {
    const body = new URLSearchParams({ secret, response: token });
    if (remoteIp) body.set('remoteip', remoteIp);
    const response = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      body,
    });
    const data = (await response.json()) as { success?: boolean };
    return data.success === true;
  } catch {
    return false;
  }
}
