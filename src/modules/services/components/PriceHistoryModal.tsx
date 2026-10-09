'use client';

import { useState, useTransition } from 'react';
import { useTranslations } from 'next-intl';
import { formatInTz } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { getPriceHistoryAction } from '@/modules/services/actions';
import type { PriceHistoryRow } from '@/modules/services/queries';

type Props = { serviceId: string; serviceName: string; tz: string };

export default function PriceHistoryModal({ serviceId, serviceName, tz }: Props) {
  const t = useTranslations('services');
  const tc = useTranslations('common');
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState<PriceHistoryRow[] | null>(null);
  const [pending, startTransition] = useTransition();

  function openModal() {
    setOpen(true);
    setRows(null);
    startTransition(async () => {
      const result = await getPriceHistoryAction(serviceId);
      setRows(result.ok && result.data ? result.data : []);
    });
  }

  return (
    <>
      <button type="button" className="btn-secondary" onClick={openModal}>
        {t('priceHistory')}
      </button>

      {open ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label={tc('close')}
            className="absolute inset-0 bg-slate-900/40"
            onClick={() => setOpen(false)}
          />
          <div
            role="dialog"
            aria-modal="true"
            aria-label={t('priceHistory')}
            className="relative z-10 w-full max-w-lg rounded-xl border border-slate-200 bg-white p-5 shadow-xl"
          >
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-sm font-semibold text-slate-900">{t('priceHistory')}</h2>
                <p className="mt-0.5 text-xs text-slate-500">{serviceName}</p>
              </div>
              <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>
                {tc('close')}
              </button>
            </div>

            <div className="mt-4 max-h-80 overflow-y-auto">
              {pending || rows === null ? (
                <p className="py-6 text-center text-sm text-slate-500">{tc('loading')}</p>
              ) : rows.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-500">{t('priceHistoryEmpty')}</p>
              ) : (
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                    <tr>
                      <th className="py-2 pr-3 font-medium">{t('oldPrice')}</th>
                      <th className="py-2 pr-3 font-medium">{t('newPrice')}</th>
                      <th className="py-2 pr-3 font-medium">{t('changedBy')}</th>
                      <th className="py-2 font-medium">{t('changedAt')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.id} className="border-b border-slate-100 last:border-0">
                        <td className="py-2 pr-3 text-slate-600">{formatMoney(row.oldPrice)}</td>
                        <td className="py-2 pr-3 font-medium text-slate-900">
                          {formatMoney(row.newPrice)}
                        </td>
                        <td className="py-2 pr-3 text-slate-600">{row.changedByName ?? '—'}</td>
                        <td className="py-2 text-slate-600">{formatInTz(row.changedAt, tz)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}
