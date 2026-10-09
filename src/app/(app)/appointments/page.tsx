import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { prisma } from '@/lib/prisma';
import {
  clinicDateKey,
  clinicTimeKey,
  combineDateAndTime,
  dateKeyAdd,
  formatInTz,
} from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { weekDays, weekStartKey } from '@/modules/hr/shifts';
import {
  APPT_ROLES,
  APPOINTMENT_SOURCE_LABEL_KEYS,
  APPOINTMENT_STATUS_LABEL_KEYS,
  DATE_RE,
  listAppointments,
  type AppointmentRow,
} from '@/modules/appointments';
import NewAppointmentButton from '@/modules/appointments/components/NewAppointmentButton';
import AppointmentRowButtons from '@/modules/appointments/components/AppointmentRowButtons';
import type { RowAppointment } from '@/modules/appointments/components/AppointmentRowButtons';
import AutoSubmitSelect from '@/components/AutoSubmitSelect';
import ExportLinks from '@/components/ExportLinks';

type SearchParams = Promise<{ view?: string; day?: string; doctor?: string }>;

const STATUS_CLASS: Record<string, string> = {
  SCHEDULED: 'bg-slate-100 text-slate-600',
  CONFIRMED: 'bg-emerald-50 text-emerald-700',
  COMPLETED: 'bg-blue-50 text-blue-700',
  NO_SHOW: 'bg-amber-50 text-amber-700',
  CANCELLED: 'bg-red-50 text-red-700',
};

type DoctorOption = { id: string; fullName: string };
type ServiceOption = { id: string; name: string; nameAm: string | null };

function asRowAppointment(row: AppointmentRow, timeZone: string): RowAppointment {
  const minutes = Math.max(1, Math.round((row.endsAt.getTime() - row.startsAt.getTime()) / 60_000));
  return {
    id: row.id,
    patientId: row.patientId,
    patientName: row.patientName,
    doctorId: row.doctorId,
    serviceId: row.serviceId,
    dateKey: clinicDateKey(row.startsAt, timeZone),
    startTime: clinicTimeKey(row.startsAt, timeZone),
    durationMin: minutes,
    status: row.status,
    notes: row.notes,
  };
}

export default async function AppointmentsPage({ searchParams }: { searchParams: SearchParams }) {
  const actor = await requirePageRole(APPT_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('appointments');
  const tc = await getTranslations('common');

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const todayKey = clinicDateKey(new Date(), tz);
  const anchor = sp.day && DATE_RE.test(sp.day) ? sp.day : todayKey;
  const view = sp.view === 'day' || sp.view === 'week' ? sp.view : 'list';
  const selectedDoctor = sp.doctor ?? '';

  const weekStart = weekStartKey(anchor);
  const { fromKey, toKey } =
    view === 'week'
      ? { fromKey: weekStart, toKey: dateKeyAdd(weekStart, 6) }
      : view === 'day'
        ? { fromKey: anchor, toKey: anchor }
        : { fromKey: todayKey, toKey: dateKeyAdd(todayKey, 14) };

  const from = combineDateAndTime(fromKey, '00:00', tz);
  const to = combineDateAndTime(dateKeyAdd(toKey, 1), '00:00', tz);

  const [appointments, doctors, services] = await Promise.all([
    listAppointments({ from, to, ...(selectedDoctor ? { doctorId: selectedDoctor } : {}) }),
    prisma.staff.findMany({
      where: { active: true, isDoctor: true },
      select: { id: true, fullName: true },
      orderBy: { fullName: 'asc' },
    }),
    prisma.service.findMany({
      where: { active: true },
      select: { id: true, name: true, nameAm: true },
      orderBy: { name: 'asc' },
    }),
  ]);

  const doctorOptions: DoctorOption[] = [{ id: '', fullName: t('allDoctors') }, ...doctors];
  const serviceOptions: ServiceOption[] = services;

  const rows = appointments.map((row) => asRowAppointment(row, tz));
  const byDateKey = new Map<string, AppointmentRow[]>();
  for (const row of appointments) {
    const key = clinicDateKey(row.startsAt, tz);
    byDateKey.set(key, [...(byDateKey.get(key) ?? []), row]);
  }

  const shift = view === 'week' ? 7 : 1;
  const prevKey = dateKeyAdd(anchor, -shift);
  const nextKey = dateKeyAdd(anchor, shift);

  const linkWith = (partial: Partial<Record<'view' | 'day' | 'doctor', string>>) => {
    const params = new URLSearchParams();
    if (view) params.set('view', view);
    if (anchor) params.set('day', anchor);
    if (selectedDoctor) params.set('doctor', selectedDoctor);
    for (const [key, value] of Object.entries(partial)) {
      if (value) params.set(key, value);
      else params.delete(key);
    }
    return `/appointments?${params.toString()}`;
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <div className="flex flex-wrap items-center gap-2">
          <ExportLinks base="/api/appointments/export" />
          <Link
            href={linkWith({ view: 'list' })}
            className={view === 'list' ? 'btn-primary' : 'btn-secondary'}
          >
            {t('viewList')}
          </Link>
          <Link
            href={linkWith({ view: 'day' })}
            className={view === 'day' ? 'btn-primary' : 'btn-secondary'}
          >
            {t('viewDay')}
          </Link>
          <Link
            href={linkWith({ view: 'week' })}
            className={view === 'week' ? 'btn-primary' : 'btn-secondary'}
          >
            {t('viewWeek')}
          </Link>
          <NewAppointmentButton doctors={doctorOptions} services={serviceOptions} />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Link href={linkWith({ day: prevKey })} className="btn-secondary">
          {tc('previous')}
        </Link>
        <Link href={linkWith({ day: todayKey })} className="btn-secondary">
          {t('today')}
        </Link>
        <Link href={linkWith({ day: nextKey })} className="btn-secondary">
          {tc('next')}
        </Link>

        <form method="get" action="/appointments" className="flex items-center gap-2">
          <input type="hidden" name="view" value={view} />
          <input type="hidden" name="day" value={anchor} />
          <AutoSubmitSelect name="doctor" defaultValue={selectedDoctor} ariaLabel={t('doctor')}>
            {doctorOptions.map((doctor) => (
              <option key={doctor.id} value={doctor.id}>
                {doctor.fullName}
              </option>
            ))}
          </AutoSubmitSelect>
        </form>
      </div>

      {view === 'week' ? (
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
          {weekDays(weekStart).map((day) => {
            const dayRows = byDateKey.get(day) ?? [];
            return (
              <div key={day} className="card space-y-2 p-3">
                <h3 className="text-xs font-semibold uppercase text-slate-500">
                  {formatInTz(combineDateAndTime(day, '12:00', tz), tz, {
                    weekday: 'short',
                    day: 'numeric',
                  })}
                </h3>
                {dayRows.map((row) => (
                  <div key={row.id} className="rounded-lg border border-slate-100 p-2">
                    <div className="text-xs font-medium text-slate-900">
                      <span className="font-semibold">{clinicTimeKey(row.startsAt, tz)}</span> ·{' '}
                      {row.patientName}
                    </div>
                    <div className="text-xs text-slate-500">
                      {row.doctorName}
                      {row.serviceName ? ` · ${row.serviceName}` : ''}
                    </div>
                    <div className="mt-1">
                      <AppointmentRowButtons
                        appointment={asRowAppointment(row, tz)}
                        canOverride={actor.role === 'OWNER'}
                        doctors={doctorOptions}
                        services={serviceOptions}
                      />
                    </div>
                  </div>
                ))}
                {dayRows.length === 0 ? (
                  <p className="text-xs text-slate-400">{t('noAppointments')}</p>
                ) : null}
              </div>
            );
          })}
        </div>
      ) : (
        <div className="card overflow-x-auto p-5">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
                <th className="py-2 pr-3 font-medium">{t('when')}</th>
                <th className="py-2 pr-3 font-medium">{t('patient')}</th>
                <th className="py-2 pr-3 font-medium">{t('doctor')}</th>
                <th className="py-2 pr-3 font-medium">{t('service')}</th>
                <th className="py-2 pr-3 font-medium">{tc('status')}</th>
                <th className="py-2 pr-3 font-medium">{t('source')}</th>
                <th className="py-2 font-medium" />
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-6 text-center text-slate-500">
                    {tc('noResults')}
                  </td>
                </tr>
              ) : (
                appointments.map((row) => (
                  <tr key={row.id} className="border-b border-slate-100 last:border-0">
                    <td className="py-2 pr-3 text-slate-800">
                      {formatInTz(row.startsAt, tz, {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                      })}
                    </td>
                    <td className="py-2 pr-3 font-medium text-slate-900">{row.patientName}</td>
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
                    <td className="py-2 pr-3 text-xs text-slate-500">
                      {t(APPOINTMENT_SOURCE_LABEL_KEYS[row.createdFrom as never] as never)}
                    </td>
                    <td className="py-2 text-right">
                      <AppointmentRowButtons
                        appointment={asRowAppointment(row, tz)}
                        canOverride={actor.role === 'OWNER'}
                        doctors={doctorOptions}
                        services={serviceOptions}
                      />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
