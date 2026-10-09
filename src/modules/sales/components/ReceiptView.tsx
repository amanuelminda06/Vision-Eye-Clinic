import { getTranslations } from 'next-intl/server';
import { formatMoney } from '@/lib/money';
import { formatInTz } from '@/lib/datetime';
import { PAYMENT_LABEL_KEYS } from '../constants';
import type { SaleDetail } from '../queries';

type Props = {
  sale: SaleDetail;
  clinicName: string;
  currency: string;
  timeZone: string;
};

/** Printable receipt — A5 by default, 80mm thermal via PrintButtons. */
export default async function ReceiptView({ sale, clinicName, currency, timeZone }: Props) {
  const t = await getTranslations('sales');

  return (
    <div
      id="receipt"
      data-print-mode="a5"
      className="mx-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 text-slate-900"
    >
      <header className="text-center">
        <h2 className="text-lg font-semibold">{clinicName}</h2>
        <p className="text-xs uppercase tracking-wide text-slate-500">{t('receiptTitle')}</p>
      </header>

      <dl className="mt-4 grid grid-cols-2 gap-y-1 text-sm">
        <dt className="text-slate-500">{t('receiptNumber')}</dt>
        <dd className="text-right font-medium">{sale.receiptNumber}</dd>

        <dt className="text-slate-500">{t('date')}</dt>
        <dd className="text-right">{formatInTz(sale.saleDate, timeZone)}</dd>

        <dt className="text-slate-500">{t('patient')}</dt>
        <dd className="text-right font-medium">{sale.patient?.fullName ?? t('walkIn')}</dd>

        {sale.doctor ? (
          <>
            <dt className="text-slate-500">{t('doctor')}</dt>
            <dd className="text-right">{sale.doctor.fullName}</dd>
          </>
        ) : null}

        <dt className="text-slate-500">{t('paymentMethod')}</dt>
        <dd className="text-right">{t(PAYMENT_LABEL_KEYS[sale.paymentMethod] as never)}</dd>
      </dl>

      <table className="mt-4 w-full text-sm">
        <thead>
          <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <th className="py-1 pr-2 text-left font-medium">{t('service')}</th>
            <th className="py-1 px-2 text-right font-medium">{t('qty')}</th>
            <th className="py-1 pl-2 text-right font-medium">{t('lineTotal')}</th>
          </tr>
        </thead>
        <tbody>
          {sale.lines.map((line) => (
            <tr key={line.id} className="border-b border-slate-100 last:border-0">
              <td className="py-1 pr-2">{line.serviceNameSnapshot}</td>
              <td className="py-1 px-2 text-right">{line.qty}</td>
              <td className="py-1 pl-2 text-right">{formatMoney(line.lineTotal, currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-semibold">
        <span>{t('total')}</span>
        <span>{formatMoney(sale.total, currency)}</span>
      </div>

      {sale.status === 'VOIDED' ? (
        <p className="mt-3 rounded bg-red-50 px-2 py-1 text-center text-xs font-semibold text-red-700">
          {t('voidedWatermark')}
        </p>
      ) : null}

      <p className="mt-4 text-center text-xs text-slate-500">{t('receiptFooter')}</p>
    </div>
  );
}
