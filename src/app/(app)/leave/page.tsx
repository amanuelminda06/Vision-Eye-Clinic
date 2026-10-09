import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  HR_LEAVE_ROLES,
  LEAVE_STATUS_LABEL_KEYS,
  LEAVE_TYPE_LABEL_KEYS,
  canCancelLeave,
  canRequestForOthers,
  cancelLeaveAction,
  getLeaveBalance,
  getUserStaffId,
  listLeaveForStaff,
  listStaffOptions,
  requestLeaveAction,
  requestedDays,
  type LeaveRow,
  type LeaveStatusValue,
  type LeaveTypeValue,
} from '@/modules/hr';
import LeaveRequestForm from '@/modules/hr/components/LeaveRequestForm';
import CancelLeaveButton from '@/modules/hr/components/CancelLeaveButton';
import ExportLinks from '@/components/ExportLinks';

function statusClass(status: string): string {
  if (status === 'APPROVED') return 'bg-emerald-50 text-emerald-700';
  if (status === 'REJECTED') return 'bg-red-50 text-red-700';
  if (status === 'CANCELLED') return 'bg-slate-100 text-slate-500';
  return 'bg-amber-50 text-amber-700';
}

export default async function LeavePage() {
  const user = await requirePageRole(HR_LEAVE_ROLES);
  const t = await getTranslations('hr');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const today = clinicDateKey(new Date(), tz);
  const year = Number(today.slice(0, 4));

  const selfStaffId = await getUserStaffId(user.id);
  const staffOptions = canRequestForOthers(user.role) ? await listStaffOptions() : [];

  const [requests, balance] = await Promise.all([
    selfStaffId ? listLeaveForStaff(selfStaffId) : Promise.resolve<LeaveRow[]>([]),
    selfStaffId ? getLeaveBalance(selfStaffId, year) : Promise.resolve(null),
  ]);

  const fmtDate = (value: Date): string => value.toISOString().slice(0, 10);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('leaveTitle')}</h1>
        <ExportLinks base="/api/leave/export" />
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        {selfStaffId || canRequestForOthers(user.role) ? (
          <LeaveRequestForm
            action={requestLeaveAction}
            staffOptions={staffOptions}
            canForOthers={canRequestForOthers(user.role)}
            defaultStaffId={selfStaffId ?? ''}
            today={today}
          />
        ) : (
          <p className="card p-5 text-sm text-slate-600">{t('noStaff')}</p>
        )}

        <section className="card space-y-3 p-5">
          <h2 className="text-sm font-semibold text-slate-800">{t('balance')}</h2>
          {balance && balance.entitlement !== null ? (
            <dl className="grid grid-cols-3 gap-3 text-center">
              <div>
                <dt className="text-xs text-slate-500">{t('balanceEntitlement')}</dt>
                <dd className="text-lg font-semibold text-slate-900">{balance.entitlement}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">{t('balanceTaken')}</dt>
                <dd className="text-lg font-semibold text-slate-900">{balance.taken}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">{t('balanceRemaining')}</dt>
                <dd className="text-lg font-semibold text-slate-900">{balance.remaining}</dd>
              </div>
            </dl>
          ) : (
            <p className="text-sm text-slate-500">{t('balanceUnset')}</p>
          )}
          <p className="text-xs text-slate-400">{t('balanceHint')}</p>
        </section>
      </div>

      <section className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('leaveType')}</th>
              <th className="px-4 py-3 font-medium">{t('startDate')}</th>
              <th className="px-4 py-3 font-medium">{t('endDate')}</th>
              <th className="px-4 py-3 font-medium">{t('days')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 font-medium">{t('decisionNote')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {requests.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                  {t('noLeave')}
                </td>
              </tr>
            ) : (
              requests.map((row) => {
                const isSelf = !!selfStaffId && selfStaffId === row.staffId;
                return (
                  <tr key={row.id} className="border-b border-slate-100 last:border-0">
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
                    <td className="px-4 py-3 text-slate-500">{row.decisionNote ?? '—'}</td>
                    <td className="px-4 py-3">
                      {canCancelLeave({ role: user.role, isSelf, status: row.status }) ? (
                        <CancelLeaveButton action={cancelLeaveAction} leaveId={row.id} />
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </section>
    </div>
  );
}
