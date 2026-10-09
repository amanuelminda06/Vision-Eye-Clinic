import { connection, NextResponse } from 'next/server';
import { env } from '@/env';
import { runMessageJobs } from '@/modules/messaging/generate';

/**
 * Scheduled message generation + expiry. Called by an external cron (every 15
 * minutes, plus a nightly run) with `Authorization: Bearer $CRON_SECRET`.
 * Disabled when CRON_SECRET is unset. Safe to call repeatedly (idempotent).
 */
export async function POST(request: Request) {
  await connection();

  const secret = env.CRON_SECRET;
  if (!secret) {
    return NextResponse.json({ ok: false, error: 'disabled' }, { status: 503 });
  }
  const header = request.headers.get('authorization') ?? '';
  if (header !== `Bearer ${secret}`) {
    return NextResponse.json({ ok: false, error: 'unauthorized' }, { status: 401 });
  }

  const summary = await runMessageJobs(new Date());
  return NextResponse.json({ ok: true, summary }, { headers: { 'Cache-Control': 'no-store' } });
}
