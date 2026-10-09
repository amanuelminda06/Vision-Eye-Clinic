import { connection } from 'next/server';
import { getPublicDoctorStatus } from '@/modules/hr';

/**
 * Public, unauthenticated "who is on duty now" feed for the clinic website.
 *
 * The projection is an allow-list (see `buildPublicDoctorStatus`): no phone,
 * email, leave reasons or any internal identifiers ever leave the clinic. The
 * route is excluded from the session gate by `src/proxy.ts` (its matcher skips
 * `/api`) and is cached for 60 seconds at the HTTP layer.
 */
export async function GET() {
  await connection();
  const doctors = await getPublicDoctorStatus();

  return Response.json(
    { doctors, generatedAt: new Date().toISOString() },
    {
      headers: {
        'Cache-Control': 'public, max-age=60, stale-while-revalidate=120',
      },
    },
  );
}
