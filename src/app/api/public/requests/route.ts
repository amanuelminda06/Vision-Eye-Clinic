import { connection, NextResponse } from 'next/server';
import { z } from 'zod';
import { validate, ValidationError } from '@/lib/errors';
import { parsePhone } from '@/lib/phone';
import { hashClientIp } from '@/lib/ip';
import { checkRateLimit } from '@/lib/ratelimit';
import { createPublicBooking } from '@/modules/appointments/actions';
import { bookingFormSchema, isHoneypotTripped } from '@/modules/public-site/schemas';
import { verifyTurnstile } from '@/modules/public-site/turnstile';

/** Booking spam guard: 5 submissions per hour per IP (in addition to the honeypot). */
export const PUBLIC_BOOKING_RATE_MAX = 5;
export const PUBLIC_BOOKING_RATE_WINDOW_MS = 60 * 60_000;

/**
 * Public, unauthenticated endpoint for the clinic website's "book an
 * appointment" form. The booking is auto-approved: it becomes a CONFIRMED
 * appointment (doctor assigned server-side) instead of a request to review.
 * Defences: honeypot field, per-IP rate limit, optional Cloudflare Turnstile
 * (off by default), strict zod validation, and only the sha-256 IP hash is
 * kept — never the raw IP. Excluded from the session gate by `src/proxy.ts`
 * (its matcher skips `/api`).
 */
export async function POST(request: Request) {
  await connection();

  const ip = request.headers.get('x-forwarded-for')?.split(',')[0].trim() ?? null;
  if (
    !checkRateLimit(`public-booking:${ip ?? 'unknown'}`, {
      max: PUBLIC_BOOKING_RATE_MAX,
      windowMs: PUBLIC_BOOKING_RATE_WINDOW_MS,
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

  let values: z.infer<typeof bookingFormSchema>;
  try {
    values = validate(bookingFormSchema, body as Record<string, unknown>);
  } catch (err) {
    const fields = err instanceof ValidationError ? err.fields : {};
    return NextResponse.json({ ok: false, error: 'validationError', fields }, { status: 422 });
  }

  // Honeypot: silently accept-and-drop so bots do not learn they were caught.
  if (isHoneypotTripped(values)) {
    return NextResponse.json({ ok: true, id: null }, { headers: { 'Cache-Control': 'no-store' } });
  }

  if (!(await verifyTurnstile(values.turnstileToken, ip))) {
    return NextResponse.json(
      { ok: false, error: 'validationError', fields: { turnstileToken: 'required' } },
      { status: 422 },
    );
  }

  const phone = parsePhone(values.phone);
  if (!phone.ok) {
    return NextResponse.json(
      { ok: false, error: 'validationError', fields: { phone: phone.error } },
      { status: 422 },
    );
  }

  const result = await createPublicBooking({
    name: values.name,
    phoneE164: phone.value,
    preferredDate: values.preferredDate,
    timeWindow: values.preferredTimeWindow,
    serviceInterest: values.serviceInterest,
    message: values.message,
    language: values.language,
    ipHash: hashClientIp(ip),
  });

  if (!result.ok) {
    // Date/window problems are the visitor's to fix; show them inline.
    if (result.error === 'invalidDate' || result.error === 'pastSlot') {
      return NextResponse.json(
        { ok: false, error: 'validationError', fields: { preferredDate: result.error } },
        { status: 422 },
      );
    }
    const status = result.error === 'noDoctorAvailable' ? 503 : 500;
    return NextResponse.json({ ok: false, error: result.error }, { status });
  }

  return NextResponse.json(
    { ok: true, id: result.id, appointment: result.appointment },
    { headers: { 'Cache-Control': 'no-store' } },
  );
}
