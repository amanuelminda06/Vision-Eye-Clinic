'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { formatDateRange } from '@/lib/datetime';
import { deletePatientSickLeaveAction } from '../actions';
import type { PatientSickLeaveRow } from '../queries';

type Props = {
  rows: PatientSickLeaveRow[];
  timeZone: string;
  route: string;
};

/** Table of a patient's sick-leave records with edit/delete actions. */
export default function PatientSickLeaveList({ rows, timeZone, route }: Props) {
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const [pendingId, setPendingId] = useState<string | null>(null);

  async function onDelete(id: string) {
    if (!confirm(t('sickDeleteConfirm'))) return;
    setPendingId(id);
    const fd = new FormData();
    fd.append('id', id);
    const res = await deletePatientSickLeaveAction(null, fd);
    setPendingId(null);
    if (res?.ok) window.location.reload();
  }

  if (rows.length === 0) {
    return (
      <div className="card p-5 text-sm text-slate-500">
        <p>{t('sickEmpty')}</p>
        <Link
          href={`${route}?tab=sick`}
          className="mt-2 inline-block text-xs font-medium text-orange-700 hover:underline"
        >
          {t('sickAddFirst')}
        </Link>
      </div>
    );
  }

  return (
    <div className="card overflow-hidden p-0">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50">
          <tr>
            <th className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              {t('sickStartDate')}
            </th>
            <th className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              {t('sickEndDate')}
            </th>
            <th className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              {t('sickReason')}
            </th>
            <th className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              {t('sickRecordedBy')}
            </th>
            <th className="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">
              {tc('actions')}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row) => (
            <tr key={row.id} className={pendingId === row.id ? 'opacity-50' : ''}>
              <td className="px-4 py-2 text-slate-700">
                {formatDateRange(row.startDate, row.endDate, timeZone)}
              </td>
              <td className="px-4 py-2 text-slate-700">{row.reason || '—'}</td>
              <td className="px-4 py-2 text-slate-700">
                {row.note ? (
                  <span title={row.note} className="line-clamp-2">
                    {row.note}
                  </span>
                ) : (
                  '—'
                )}
              </td>
              <td className="px-4 py-2 text-slate-500">{row.recordedByName ?? '—'}</td>
              <td className="px-4 py-2 text-right">
                <div className="flex justify-end gap-2">
                  <Link
                    href={`${route}?tab=sick&edit=${row.id}`}
                    className="text-xs font-medium text-orange-700 hover:underline"
                  >
                    {tc('edit')}
                  </Link>
                  <button
                    type="button"
                    onClick={() => onDelete(row.id)}
                    disabled={pendingId === row.id}
                    className="text-xs font-medium text-red-600 hover:underline"
                  >
                    {tc('delete')}
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
