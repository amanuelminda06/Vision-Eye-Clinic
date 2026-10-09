import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { prisma } from '@/lib/prisma';
import { clinicDateKey, combineDateAndTime, formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  FOLLOW_UP_ROLES,
  FOLLOW_UP_STATUS_LABEL_KEYS,
  listFollowUps,
} from '@/modules/appointments';
import FollowUpRowActions from '@/modules/appointments/components/FollowUpRowActions';
import AutoSubmitSelect from '@/components/AutoSubmitSelect';

type SearchParams = Promise<{ status?: string; patient?: string }>;

const STATUS_TINT: Record<string, string> = {
  OPEN: 'bg-emerald-50 text-emerald-700',
  DONE: 'bg-slate-100 text-slate-500',
  CANCELLED: 'bg-red-50 text-red-700',
};

export default async function FollowUpsPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(FOLLOW_UP_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('followups');
  const tc = await getTranslations('common');

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const todayKey = clinicDateKey(new Date(), tz);

  const tab =
    sp.status === 'done' || sp.status === 'cancelled' || sp.status === 'overdue'
      ? sp.status
      : 'open';

  const [rows, patients] = await Promise.all([
    listFollowUps({
      status:
        tab === 'open'
          ? 'OPEN'
          : tab === 'done'
            ? 'DONE'
            : tab === 'cancelled'
              ? 'CANCELLED'
              : 'OPEN',
      ...(tab === 'overdue' ? { dueBeforeKey: todayKey } : {}),
      ...(sp.patient ? { patientId: sp.patient } : {}),
    }),
    prisma.patient.findMany({
      where: { active: true },
      select: { id: true, fullName: true },
      orderBy: { fullName: 'asc' },
      take: 300,
    }),
  ]);

  const link = (status: string, patient?: string) => {
    const params = new URLSearchParams();
    if (status !== 'open') params.set('status', status);
    if (patient) params.set('patient', patient);
    return `/follow-ups?${params.toString()}`;
  };

  const tabs = [
    { key: 'open', label: t('tabOpen') },
    { key: 'overdue', label: t('tabOverdue') },
    { key: 'done', label: t('tabDone') },
    { key: 'cancelled', label: t('tabCancelled') },
  ] as const;

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>

      <div className="flex flex-wrap items-center gap-2">
        {tabs.map((item) => (
          <Link
            key={item.key}
            href={link(item.key, sp.patient)}
            className={tab === item.key ? 'btn-primary' : 'btn-secondary'}
          >
            {item.label}
          </Link>
        ))}

        <form method="get" action="/follow-ups" className="flex items-center gap-2">
          <input type="hidden" name="status" value={tab} />
          <AutoSubmitSelect
            name="patient"
            defaultValue={sp.patient ?? ''}
            ariaLabel={t('patientFilter')}
          >
            <option value="">{t('allPatients')}</option>
            {patients.map((patient) => (
              <option key={patient.id} value={patient.id}>
                {patient.fullName}
              </option>
            ))}
          </AutoSubmitSelect>
        </form>
      </div>

      <div className="card overflow-x-auto p-5">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <th className="py-2 pr-3 font-medium">{t('patient')}</th>
              <th className="py-2 pr-3 font-medium">{t('dueDate')}</th>
              <th className="py-2 pr-3 font-medium">{t('reason')}</th>
              <th className="py-2 pr-3 font-medium">{tc('status')}</th>
              <th className="py-2 font-medium" />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-6 text-center text-slate-500">
                  {tc('noResults')}
                </td>
              </tr>
            ) : (
              rows.map((row) => {
                const overdue =
                  row.status === 'OPEN' &&
                  row.dueDate.getTime() < combineDateAndTime(todayKey, '00:00', tz).getTime();
                return (
                  <tr key={row.id} className="border-b border-slate-100 last:border-0">
                    <td className="py-2 pr-3 font-medium text-slate-900">
                      <Link
                        href={`/patients/${row.patientId}`}
                        prefetch={false}
                        className="hover:underline"
                      >
                        {row.patientName}
                      </Link>
                    </td>
                    <td className="py-2 pr-3 text-slate-800">
                      <span className={overdue ? 'font-medium text-red-700' : ''}>
                        {formatInTz(row.dueDate, tz, { dateStyle: 'medium' })}
                      </span>
                      {overdue ? (
                        <span className="ml-1 text-xs text-red-600">{t('overdueTag')}</span>
                      ) : null}
                    </td>
                    <td className="py-2 pr-3 text-slate-600">{row.reason ?? '—'}</td>
                    <td className="py-2 pr-3">
                      <span
                        className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                          STATUS_TINT[row.status] ?? 'bg-slate-100 text-slate-600'
                        }`}
                      >
                        {t(FOLLOW_UP_STATUS_LABEL_KEYS[row.status as never] as never)}
                      </span>
                    </td>
                    <td className="py-2 text-right">
                      <FollowUpRowActions followUpId={row.id} status={row.status} />
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
