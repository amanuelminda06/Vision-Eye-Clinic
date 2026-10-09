import { connection, NextResponse } from 'next/server';
import { auth } from '@/auth';
import { countDueTasks } from '@/modules/messaging/queries';

/**
 * Polled by MessagesTicker to prefix the browser tab title with the number of
 * messages due now or overdue. Session-gated (the proxy skips all /api routes).
 */
export async function GET() {
  await connection();
  const session = await auth();
  if (!session?.user?.id) {
    return NextResponse.json({ ok: false }, { status: 401 });
  }
  const count = await countDueTasks().catch(() => 0);
  return NextResponse.json({ ok: true, count }, { headers: { 'Cache-Control': 'no-store' } });
}
