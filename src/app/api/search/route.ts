import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { prisma } from '@/lib/prisma';
import { toDecimalString } from '@/lib/money';
import { phoneSearchVariants } from '@/lib/phone';
import { clinicDateKey, formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';

export type SearchGroup = 'patients' | 'sales' | 'appointments';
export type SearchHit = {
  id: string;
  title: string;
  subtitle: string;
  href: string;
};
export type SearchResponse = Record<SearchGroup, SearchHit[]>;

const SEARCH_ROLES = ['OWNER', 'RECEPTION'] as const;
const MAX_QUERY = 100;

function hitSorter(a: SearchHit, b: SearchHit): number {
  return a.title.localeCompare(b.title);
}

/** As-you-type global search (patients, sales, appointments). Role-gated. */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole([...SEARCH_ROLES]);
  } catch (err) {
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') {
        return Response.redirect(new URL('/change-password', request.url));
      }
      return new Response('Forbidden', { status: 403 });
    }
    throw err;
  }

  const q = (request.nextUrl.searchParams.get('q') ?? '').trim().slice(0, MAX_QUERY);
  if (q.length < 2) {
    return Response.json(
      { patients: [], sales: [], appointments: [] } satisfies SearchResponse,
      { headers: { 'Cache-Control': 'no-store' } },
    );
  }

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const patientWhere =
    q.length >= 3
      ? {
          active: true,
          OR: [
            { fullName: { contains: q, mode: 'insensitive' as const } },
            ...(phoneSearchVariants(q) ?? []).map((variant) => ({
              phoneE164: { contains: variant },
            })),
          ],
        }
      : undefined;

  const [patients, sales, appointments] = await Promise.all([
    patientWhere
      ? prisma.patient.findMany({
          where: patientWhere,
          select: { id: true, fullName: true, phoneE164: true },
          orderBy: { fullName: 'asc' },
          take: 6,
        })
      : Promise.resolve([]),
    prisma.sale.findMany({
      where: {
        OR: [
          { receiptNumber: { contains: q, mode: 'insensitive' as const } },
          { patient: { fullName: { contains: q, mode: 'insensitive' as const } } },
          ...(phoneSearchVariants(q) ?? []).map((variant) => ({
            patient: { phoneE164: { contains: variant } },
          })),
        ],
      },
      select: {
        id: true,
        receiptNumber: true,
        saleDate: true,
        total: true,
        patient: { select: { fullName: true } },
      },
      orderBy: { saleDate: 'desc' },
      take: 6,
    }),
    prisma.appointment.findMany({
      where: {
        patient: { fullName: { contains: q, mode: 'insensitive' as const } },
      },
      select: {
        id: true,
        startsAt: true,
        status: true,
        patient: { select: { fullName: true } },
        doctor: { select: { fullName: true } },
      },
      orderBy: { startsAt: 'desc' },
      take: 6,
    }),
  ]);

  void actor;

  const response: SearchResponse = {
    patients: patients.map((patient) => ({
      id: patient.id,
      title: patient.fullName,
      subtitle: patient.phoneE164,
      href: `/patients/${patient.id}`,
    })),
    sales: sales.map((sale) => ({
      id: sale.id,
      title: sale.receiptNumber,
      subtitle: `${sale.patient?.fullName ?? ''} · ${toDecimalString(sale.total)} · ${formatInTz(sale.saleDate, tz)}`,
      href: `/sales/${sale.id}`,
    })),
    appointments: appointments.map((appointment) => ({
      id: appointment.id,
      title: appointment.patient.fullName,
      subtitle: `${clinicDateKey(appointment.startsAt, tz)} · ${appointment.doctor.fullName} · ${appointment.status}`,
      href: `/appointments?view=day&day=${clinicDateKey(appointment.startsAt, tz)}`,
    })),
  };

  for (const group of Object.keys(response) as SearchGroup[]) {
    response[group].sort(hitSorter);
  }

  return Response.json(response, {
    headers: { 'Cache-Control': 'no-store' },
  });
}