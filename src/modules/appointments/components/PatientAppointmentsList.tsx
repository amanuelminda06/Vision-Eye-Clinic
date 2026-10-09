import { getTranslations } from 'next-intl/server';
import { formatInTz } from '@/lib/datetime';
import { listPatientAppointments } from '../queries';
import { APPOINTMENT_STATUS_LABEL_KEYS } from '../constants';
import type { AppointmentRow } from '../queries';

type Props = { patientId: string; timeZone: string };

const STATUS_CLASS: Record<string, string> = {
  SCHEDULED: 'bg-slate-100 text-slate-600',
  CONFIRMED: 'bg-emerald-50 text-emerald-700',
  COMPLETED: 'bg-blue-50 text-blue-700',
  NO_SHOW: 'bg-amber-50 text-amber-700',
  CANCELLED: 'bg-red-50 text-red-700',
};

/** Patient detail "Appointments" tab. */
export default async function PatientAppointmentsList({ patientId, timeZone }: Props) {
  const t = await getTranslations('appointments');
  const rows: AppointmentRow[] = await listPatientAppointments(patientId);

  if (rows.length === 0) {
    return <p className="text-sm text-slate-600">{t('patientNoAppointments')}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <th className="py-2 pr-3 font-medium">{t('startsAt')}</th>
            <th className="py-2 pr-3 font-medium">{t('doctor')}</th>
            <th className="py-2 pr-3 font-medium">{t('service')}</th>
            <th className="py-2 pr-3 font-medium">{t('status')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id} className="border-b border-slate-100 last:border-0">
              <td className="py-2 pr-3 text-slate-800">
                {formatInTz(row.startsAt, timeZone, { dateStyle: 'medium', timeStyle: 'short' })}
              </td>
              <td className="py-2 pr-3 text-slate-700">{row.doctorName}</td>
              <td className="py-2 pr-3 text-slate-600">{row.serviceName ?? '—'}</td>
              <td className="py-2 pr-3">
                <span
                  className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                    STATUS_CLASS[row.status] ?? 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {t(APPOINTMENT_STATUS_LABEL_KEYS[row.status as never] as never)}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
