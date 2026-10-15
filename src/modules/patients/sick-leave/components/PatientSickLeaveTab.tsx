'use client';

import { useSearchParams } from 'next/navigation';
import { useTranslations } from 'next-intl';
import PatientSickLeaveForm from './PatientSickLeaveForm';
import PatientSickLeaveList from './PatientSickLeaveList';
import type { PatientSickLeaveRow } from '../queries';

type Props = {
  rows: PatientSickLeaveRow[];
  timeZone: string;
  route: string;
  patientId: string;
};

/** Sick-leave tab body: list + inline create/edit form. */
export default function PatientSickLeaveTab({ rows, timeZone, route, patientId }: Props) {
  const t = useTranslations('patients');
  const sp = useSearchParams();
  const editId = sp.get('edit');
  const editing = editId ? rows.find((row) => row.id === editId) : undefined;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-sm font-semibold text-slate-800">{t('sickLeaveTitle')}</h2>
      </div>

      <PatientSickLeaveForm
        patientId={patientId}
        route={route}
        editing={
          editing
            ? {
                id: editing.id,
                startDate: editing.startDate.toISOString().slice(0, 10),
                endDate: editing.endDate.toISOString().slice(0, 10),
                reason: editing.reason,
                note: editing.note,
              }
            : undefined
        }
      />

      <PatientSickLeaveList rows={rows} timeZone={timeZone} route={route} />
    </div>
  );
}
