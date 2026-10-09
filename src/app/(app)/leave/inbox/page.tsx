import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import {
  HR_OWNER_ROLES,
  LEAVE_TYPE_LABEL_KEYS,
  approveLeaveAction,
  listPendingLeaveRequests,
  rejectLeaveAction,
  requestedDays,
  type LeaveTypeValue,
} from '@/modules/hr';
import LeaveDecisionForm from '@/modules/hr/components/LeaveDecisionForm';

export default async function LeaveInboxPage() {
  await requirePageRole(HR_OWNER_ROLES);
  const t = await getTranslations('hr');
  const rows = await listPendingLeaveRequests();

  const fmtDate = (value: Date): string => value.toISOString().slice(0, 10);

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-semibold text-slate-900">{t('leaveInboxTitle')}</h1>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('staffLabel')}</th>
              <th className="px-4 py-3 font-medium">{t('leaveType')}</th>
              <th className="px-4 py-3 font-medium">{t('startDate')}</th>
              <th className="px-4 py-3 font-medium">{t('endDate')}</th>
              <th className="px-4 py-3 font-medium">{t('days')}</th>
              <th className="px-4 py-3 font-medium">{t('reason')}</th>
              <th className="px-4 py-3 font-medium">{t('decide')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                  {t('inboxEmpty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 last:border-0 align-top">
                  <td className="px-4 py-3 font-medium text-slate-900">{row.staffName}</td>
                  <td className="px-4 py-3 text-slate-700">
                    {t(LEAVE_TYPE_LABEL_KEYS[row.type as LeaveTypeValue] as never)}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{fmtDate(row.startDate)}</td>
                  <td className="px-4 py-3 text-slate-600">{fmtDate(row.endDate)}</td>
                  <td className="px-4 py-3 text-slate-600">{requestedDays(row)}</td>
                  <td className="px-4 py-3 text-slate-500">
                    {row.reason ?? '—'}
                    {row.appointmentConflicts > 0 ? (
                      <div className="mt-1 text-xs text-amber-700">
                        {t('conflictAppointments', { count: row.appointmentConflicts })}
                      </div>
                    ) : null}
                  </td>
                  <td className="px-4 py-3">
                    <LeaveDecisionForm
                      leaveId={row.id}
                      approveAction={approveLeaveAction}
                      rejectAction={rejectLeaveAction}
                    />
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
