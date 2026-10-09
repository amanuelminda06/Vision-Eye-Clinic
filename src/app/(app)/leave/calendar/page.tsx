import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { clinicDateKey, dateKeyAdd } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  HR_TEAM_LEAVE_ROLES,
  LEAVE_STATUS_LABEL_KEYS,
  LEAVE_TYPE_LABEL_KEYS,
  listTeamLeaveInRange,
  requestedDays,
  type LeaveStatusValue,
  type LeaveTypeValue,
} from '@/modules/hr';

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

type SearchParams = Promise<{ from?: string }>;

function statusClass(status: string): string {
  if (status === 'APPROVED') return 'bg-emerald-50 text-emerald-700';
  if (status === 'REJECTED') return 'bg-red-50 text-red-700';
  if (status === 'CANCELLED') return 'bg-slate-100 text-slate-500';
  return 'bg-amber-50 text-amber-700';
}

export default async function LeaveCalendarPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(HR_TEAM_LEAVE_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('hr');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const today = clinicDateKey(new Date(), tz);
  const fromKey = sp.from && DATE_RE.test(sp.from) ? sp.from : today;
  const toKey = dateKeyAdd(fromKey, 29);
  const prevKey = dateKeyAdd(fromKey, -30);
  const nextKey = dateKeyAdd(fromKey, 30);

  const rows = await listTeamLeaveInRange(fromKey, toKey);
  const fmtDate = (value: Date): string => value.toISOString().slice(0, 10);

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-semibold text-slate-900">{t('leaveCalendarTitle')}</h1>

      <div className="flex flex-wrap items-center gap-3">
        <Link href={`/leave/calendar?from=${prevKey}`} className="btn-secondary">
          {tc('previous')}
        </Link>
        <Link href="/leave/calendar" className="btn-secondary">
          {t('today')}
        </Link>
        <Link href={`/leave/calendar?from=${nextKey}`} className="btn-secondary">
          {tc('next')}
        </Link>
        <span className="text-sm text-slate-500">
          {fromKey} — {toKey}
        </span>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('staffLabel')}</th>
              <th className="px-4 py-3 font-medium">{t('leaveType')}</th>
              <th className="px-4 py-3 font-medium">{t('startDate')}</th>
              <th className="px-4 py-3 font-medium">{t('endDate')}</th>
              <th className="px-4 py-3 font-medium">{t('days')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                  {t('noLeave')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-900">{row.staffName}</td>
                  <td className="px-4 py-3 text-slate-700">
                    {t(LEAVE_TYPE_LABEL_KEYS[row.type as LeaveTypeValue] as never)}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{fmtDate(row.startDate)}</td>
                  <td className="px-4 py-3 text-slate-600">{fmtDate(row.endDate)}</td>
                  <td className="px-4 py-3 text-slate-600">{requestedDays(row)}</td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                        statusClass(row.status)
                      }
                    >
                      {t(LEAVE_STATUS_LABEL_KEYS[row.status as LeaveStatusValue] as never)}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
