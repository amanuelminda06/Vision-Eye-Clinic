import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { formatMoney } from '@/lib/money';
import { formatInTz } from '@/lib/datetime';
import { listPatientSales } from '../queries';
import { STATUS_LABEL_KEYS } from '../constants';

type Props = { patientId: string; timeZone: string; currency: string };

const STATUS_CLASS: Record<string, string> = {
  CONFIRMED: 'bg-emerald-50 text-emerald-700',
  DRAFT: 'bg-amber-50 text-amber-700',
  VOIDED: 'bg-red-50 text-red-700',
};

/** Patient detail "Sales" tab. */
export default async function PatientSalesList({ patientId, timeZone, currency }: Props) {
  const t = await getTranslations('sales');
  const { rows, count, confirmedTotal } = await listPatientSales(patientId);

  if (rows.length === 0) {
    return <p className="text-sm text-slate-600">{t('patientNoSales')}</p>;
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span className="text-slate-600">{t('patientSalesCount', { count })}</span>
        <span className="font-medium text-slate-900">
          {t('patientTotal', { total: formatMoney(confirmedTotal, currency) })}
        </span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <th className="py-2 pr-3 font-medium">{t('receiptNumber')}</th>
              <th className="py-2 pr-3 font-medium">{t('date')}</th>
              <th className="py-2 pr-3 font-medium">{t('status')}</th>
              <th className="py-2 pr-3 text-right font-medium">{t('total')}</th>
              <th className="py-2 font-medium" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-slate-100 last:border-0">
                <td className="py-2 pr-3 font-medium text-slate-900">{row.receiptNumber}</td>
                <td className="py-2 pr-3 text-slate-600">
                  {formatInTz(row.saleDate, timeZone, { dateStyle: 'medium' })}
                </td>
                <td className="py-2 pr-3">
                  <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                      STATUS_CLASS[row.status] ?? 'bg-slate-100 text-slate-600'
                    }`}
                  >
                    {t(STATUS_LABEL_KEYS[row.status] as never)}
                  </span>
                </td>
                <td className="py-2 pr-3 text-right text-slate-800">
                  {formatMoney(row.total, currency)}
                </td>
                <td className="py-2 text-right">
                  <Link
                    href={`/sales/${row.id}`}
                    prefetch={false}
                    className="text-slate-700 hover:underline"
                  >
                    {t('view')}
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
