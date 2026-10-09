import { getTranslations } from 'next-intl/server';
import Link from 'next/link';
import { requirePageRole } from '@/lib/require-role';
import { prisma } from '@/lib/prisma';
import { formatInTz } from '@/lib/datetime';
import { formatPhoneDisplay } from '@/lib/phone';
import {
  REQUEST_ROLES,
  REQUEST_STATUSES,
  REQUEST_STATUS_LABEL_KEYS,
  getWorkingSchedule,
  listAppointmentRequests,
  type WorkingSchedule,
} from '@/modules/appointments';
import type { RequestRow } from '@/modules/appointments';
import RequestAge from '@/modules/appointments/components/RequestAge';
import RequestRowActions from '@/modules/appointments/components/RequestRowActions';

type SearchParams = Promise<{ status?: string }>;
type DoctorOption = { id: string; fullName: string };

const STATUS_TINT: Record<string, string> = {
  NEW: 'bg-red-50 text-red-700',
  CONTACTED: 'bg-amber-50 text-amber-700',
  CONVERTED: 'bg-emerald-50 text-emerald-700',
  DECLINED: 'bg-slate-100 text-slate-500',
  SPAM: 'bg-slate-100 text-slate-500',
};

export default async function RequestsPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(REQUEST_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('requests');
  const tc = await getTranslations('common');

  const statusFilter =
    sp.status && (REQUEST_STATUSES as readonly string[]).includes(sp.status) ? sp.status : '';

  const [requests, schedule, doctors] = await Promise.all([
    listAppointmentRequests({ status: statusFilter || undefined }),
    getWorkingSchedule(),
    prisma.staff.findMany({
      where: { active: true, isDoctor: true },
      select: { id: true, fullName: true },
      orderBy: { fullName: 'asc' },
    }),
  ]);

  const newCount =
    statusFilter === '' ? requests.filter((request) => request.status === 'NEW').length : null;

  const link = (status: string) => {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    return `/requests?${params.toString()}`;
  };

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>

      <div className="flex flex-wrap items-center gap-2">
        <Link href={link('')} className={!statusFilter ? 'btn-primary' : 'btn-secondary'}>
          {t('filterAll')}
          {newCount != null && newCount > 0 ? (
            <span className="ml-1 rounded-full bg-red-100 px-1.5 text-xs">{newCount}</span>
          ) : null}
        </Link>
        {REQUEST_STATUSES.map((status) => (
          <Link
            key={status}
            href={link(status)}
            className={statusFilter === status ? 'btn-primary' : 'btn-secondary'}
          >
            {t(REQUEST_STATUS_LABEL_KEYS[status] as never)}
          </Link>
        ))}
      </div>

      <div className="space-y-3">
        {requests.length === 0 ? (
          <div className="card p-6 text-center text-sm text-slate-500">{tc('noResults')}</div>
        ) : (
          requests.map((request) => (
            <RequestCard key={request.id} request={request} schedule={schedule} doctors={doctors} />
          ))
        )}
      </div>
    </div>
  );
}

async function RequestCard({
  request,
  schedule,
  doctors,
}: {
  request: RequestRow;
  schedule: WorkingSchedule;
  doctors: DoctorOption[];
}) {
  const t = await getTranslations('requests');
  const createdAtLabel = formatInTz(request.createdAt, schedule.timeZone, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
  const windowKey = request.preferredTimeWindow ? request.preferredTimeWindow.toLowerCase() : 'any';

  return (
    <div className="card space-y-3 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <span className="font-medium text-slate-900">{request.name}</span>
            <span
              className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                STATUS_TINT[request.status] ?? 'bg-slate-100 text-slate-600'
              }`}
            >
              {t(REQUEST_STATUS_LABEL_KEYS[request.status as never] as never)}
            </span>
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600">
            <a
              href={`tel:${request.phoneE164}`}
              className="inline-flex items-center gap-1 hover:underline"
            >
              {formatPhoneDisplay(request.phoneE164)}
            </a>
            <span>{request.preferredDate}</span>
            <span>{t(`window${capitalize(windowKey)}` as never)}</span>
            <RequestAge
              createdAtMs={request.createdAt.getTime()}
              hours={schedule.hours}
              timeZone={schedule.timeZone}
            />
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {t('submittedAt', { date: createdAtLabel })}
          </p>
        </div>
        <RequestRowActions
          requestId={request.id}
          patientId={request.patientId}
          status={request.status}
          doctors={doctors}
        />
      </div>

      {request.serviceInterest || request.message ? (
        <div className="grid gap-2 sm:grid-cols-2">
          {request.serviceInterest ? (
            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <span className="text-xs uppercase text-slate-500">{t('serviceInterest')}</span>
              <p className="text-slate-800">{request.serviceInterest}</p>
            </div>
          ) : null}
          {request.message ? (
            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <span className="text-xs uppercase text-slate-500">{t('message')}</span>
              <p className="whitespace-pre-wrap text-slate-800">{request.message}</p>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function capitalize(value: string): string {
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : value;
}
