import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatMoney } from '@/lib/money';
import { formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { ReasonActionButton, SALES_OWNER_ROLES, listVoidRequests } from '@/modules/sales';
import { approveVoidRequestAction, rejectVoidRequestAction } from '@/modules/sales/actions';

export default async function VoidRequestsPage() {
  await requirePageRole(SALES_OWNER_ROLES);
  const t = await getTranslations('sales');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const rows = await listVoidRequests();

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('voidRequests')}</h1>
        <Link href="/sales" className="btn-secondary">
          {tc('back')}
        </Link>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('receiptNumber')}</th>
              <th className="px-4 py-3 font-medium">{t('date')}</th>
              <th className="px-4 py-3 font-medium">{t('patient')}</th>
              <th className="px-4 py-3 font-medium">{t('reason')}</th>
              <th className="px-4 py-3 font-medium">{t('requestedBy')}</th>
              <th className="px-4 py-3 text-right font-medium">{t('total')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                  {tc('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3">
                    <Link
                      href={`/sales/${row.id}`}
                      prefetch={false}
                      className="font-medium text-slate-900 hover:underline"
                    >
                      {row.receiptNumber}
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {formatInTz(row.saleDate, tz, { dateStyle: 'medium' })}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.patientName ?? t('walkIn')}</td>
                  <td className="max-w-xs px-4 py-3 text-slate-600">{row.reason}</td>
                  <td className="px-4 py-3 text-slate-600">{row.requestedByName ?? '—'}</td>
                  <td className="px-4 py-3 text-right font-medium text-slate-900">
                    {formatMoney(row.total)}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      <ReasonActionButton
                        action={approveVoidRequestAction}
                        saleId={row.id}
                        label={t('approveVoid')}
                        title={t('approveVoidTitle')}
                        variant="primary"
                        requireReason={false}
                      />
                      <ReasonActionButton
                        action={rejectVoidRequestAction}
                        saleId={row.id}
                        label={t('rejectVoid')}
                        title={t('rejectVoidTitle')}
                        variant="danger"
                      />
                    </div>
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
