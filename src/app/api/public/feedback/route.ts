import { connection, NextResponse } from 'next/server';
import { z } from 'zod';
import { validate, ValidationError } from '@/lib/errors';
import { hashClientIp } from '@/lib/ip';
import { checkRateLimit } from '@/lib/ratelimit';
import {
  createPublicFeedback,
  PUBLIC_FEEDBACK_RATE_MAX,
  PUBLIC_FEEDBACK_RATE_WINDOW_MS,
} from '@/modules/feedback';
import { feedbackFormSchema } from '@/modules/public-site/schemas';
import { verifyTurnstile } from '@/modules/public-site/turnstile';

/**
 * Public, unauthenticated endpoint for the "share your feedback" form on the
 * contact page. Feedback starts hidden: it only reaches the public testimonials
 * section after the OWNER ticks "show on website" in the CMS. Defences mirror
 * the booking endpoint — honeypot, per-IP rate limit, optional Cloudflare
 * Turnstile (off by default), strict zod validation, and only the sha-256 IP
 * hash is kept — never the raw IP. Excluded from the session gate by
 * `src/proxy.ts` (its matcher skips `/api`).
 */
export async function POST(request: Request) {
  await connection();

  const ip = request.headers.get('x-forwarded-for')?.split(',')[0].trim() ?? null;
  if (
    !checkRateLimit(`public-feedback:${ip ?? 'unknown'}`, {
      max: PUBLIC_FEEDBACK_RATE_MAX,
      windowMs: PUBLIC_FEEDBACK_RATE_WINDOW_MS,
    })
  ) {
    return NextResponse.json({ ok: false, error: 'rateLimited' }, { status: 429 });
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ ok: false, error: 'invalidJson' }, { status: 400 });
  }

  // Honeypot: silently accept-and-drop so bots do not learn they were caught.
  // Checked on the RAW body — going through the schema first would answer a
  // bot with a 422 that names the hidden field.
  const honeypot = (body as { website?: unknown } | null)?.website;
  if (typeof honeypot === 'string' && honeypot.trim().length > 0) {
    return NextResponse.json({ ok: true, id: null }, { headers: { 'Cache-Control': 'no-store' } });
  }

  let values: z.infer<typeof feedbackFormSchema>;
  try {
    values = validate(feedbackFormSchema, body as Record<string, unknown>);
  } catch (err) {
    const fields = err instanceof ValidationError ? err.fields : {};
    return NextResponse.json({ ok: false, error: 'validationError', fields }, { status: 422 });
  }

  if (!(await verifyTurnstile(values.turnstileToken, ip))) {
    return NextResponse.json(
      { ok: false, error: 'validationError', fields: { turnstileToken: 'required' } },
      { status: 422 },
    );
  }

  const result = await createPublicFeedback({
    name: values.name,
    email: values.email,
    rating: values.rating,
    text: values.message,
    language: values.language,
    ipHash: hashClientIp(ip),
  });

  if (!result.ok) {
    return NextResponse.json({ ok: false, error: result.error }, { status: 500 });
  }

  return NextResponse.json(
    { ok: true, id: result.id },
    { headers: { 'Cache-Control': 'no-store' } },
  );
}
