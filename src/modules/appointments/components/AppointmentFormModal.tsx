'use client';

import { useActionState, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { searchPatientsAction, type PatientSearchResult } from '@/modules/sales/actions';
import { saveAppointmentAction } from '../actions';
import { DURATIONS } from '../constants';
import AppointmentError from './AppointmentError';

type ServiceOption = { id: string; name: string; nameAm: string | null };
type DoctorOption = { id: string; fullName: string };

/** Renders a single field error from the server's `fields` map, or nothing. */
function FieldError({
  fields,
  name,
  tv,
}: {
  fields?: Record<string, string>;
  name: string;
  tv: (key: string) => string;
}) {
  const key = fields?.[name];
  if (!key) return null;
  return (
    <p role="alert" className="mt-1 text-xs font-medium text-red-600">
      {tv(key as never)}
    </p>
  );
}

export type AppointmentFormInitial = {
  id?: string;
  patient?: { id: string; fullName: string } | null;
  doctorId?: string;
  dateKey?: string;
  startTime?: string;
  durationMin?: number;
  serviceId?: string;
  notes?: string;
};

export type AppointmentFormProps = {
  open: boolean;
  onClose: () => void;
  onDone: () => void;
  doctors: DoctorOption[];
  services: ServiceOption[];
  initial: AppointmentFormInitial | null;
};

type SaveState = ActionResult<{ id: string; warnings: string[] }> | null;

/**
 * Create / edit an appointment. Saving "warns but does not block": a doctor
 * double-booking returns a `doubleBooking` warning and the staff member must
 * acknowledge ("save anyway") — the acknowledgement is audited server-side.
 */
export default function AppointmentFormModal({
  open,
  onClose,
  onDone,
  doctors,
  services,
  initial,
}: AppointmentFormProps) {
  const router = useRouter();
  const t = useTranslations('appointments');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');

  const [state, formAction, pending] = useActionState<SaveState, FormData>(
    saveAppointmentAction,
    null,
  );
  const lastForm = useRef<FormData | null>(null);
  const finishedRef = useRef(false);

  const [quickCreate, setQuickCreate] = useState(false);
  const [patient, setPatient] = useState<PatientSearchResult | null>(
    initial?.patient
      ? { id: initial.patient.id, fullName: initial.patient.fullName, phoneE164: '' }
      : null,
  );
  const [patientQuery, setPatientQuery] = useState(initial?.patient?.fullName ?? '');
  const [results, setResults] = useState<PatientSearchResult[]>([]);
  const [acknowledged, setAcknowledged] = useState(false);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (state?.ok && !pending && !finishedRef.current) {
      finishedRef.current = true;
      router.refresh();
      onDone();
    }
  }, [state, pending, router, onDone]);

  if (!open) return null;

  const failed = !!state && !state.ok;
  const fields = failed ? (state.fields as Record<string, string> | undefined) : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  const savedWarnings = state?.ok ? (state.data?.warnings ?? []) : [];
  const doubleBooked = savedWarnings.includes('doubleBooking');
  const showOverridePrompt = doubleBooked && !acknowledged;
  const otherWarnings = savedWarnings.filter((key) => key !== 'doubleBooking');

  function onPatientQueryChange(value: string) {
    setPatientQuery(value);
    setPatient(null);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (value.trim().length < 2) {
      setResults([]);
      return;
    }
    searchTimer.current = setTimeout(() => {
      void searchPatientsAction(value).then((result) => {
        if (result.ok && result.data) setResults(result.data);
      });
    }, 250);
  }

  function submit(formData: FormData) {
    lastForm.current = formData;
    formAction(formData);
  }

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    submit(new FormData(event.currentTarget));
  }

  function saveAnyway() {
    const replay = new FormData();
    if (lastForm.current) {
      for (const [key, value] of lastForm.current.entries()) replay.append(key, value);
    }
    replay.set('overrideDoubleBooking', 'true');
    setAcknowledged(true);
    submit(replay);
  }

  const todayKey = new Date().toISOString().slice(0, 10);
  const patientIdField = patient ? patient.id : '';

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 sm:p-8">
      <div className="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900">
            {initial?.id ? t('editAppointment') : t('newAppointment')}
          </h2>
          <button type="button" className="btn-secondary" onClick={onClose}>
            {tc('close')}
          </button>
        </div>

        <form onSubmit={onSubmit} className="space-y-4">
          <input type="hidden" name="id" value={initial?.id ?? ''} />

          {/* Patient selection */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <label className="text-sm font-medium text-slate-700">{t('patient')}</label>
              <button
                type="button"
                className={quickCreate ? 'btn-primary' : 'btn-secondary'}
                onClick={() => {
                  setQuickCreate((value) => !value);
                  setPatient(null);
                  setPatientQuery('');
                  setResults([]);
                }}
              >
                {t('quickCreate')}
              </button>
            </div>

            {quickCreate ? (
              <div className="grid gap-2 sm:grid-cols-2">
                <div>
                  <input
                    className="input-field"
                    name="quickName"
                    placeholder={t('quickName')}
                    aria-label={t('quickName')}
                  />
                  {fieldError('quickName') ? (
                    <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                      {fieldError('quickName')}
                    </p>
                  ) : null}
                </div>
                <div>
                  <input
                    className="input-field"
                    name="quickPhone"
                    placeholder={t('quickPhoneHint')}
                    aria-label={t('quickPhoneHint')}
                  />
                  {fieldError('quickPhone') ? (
                    <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                      {fieldError('quickPhone')}
                    </p>
                  ) : null}
                </div>
              </div>
            ) : patient ? (
              <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2">
                <div>
                  <div className="text-sm font-medium text-slate-900">{patient.fullName}</div>
                  <div className="text-xs text-slate-500">{patient.phoneE164 || '—'}</div>
                </div>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => {
                    setPatient(null);
                    setPatientQuery('');
                  }}
                >
                  {tc('clear')}
                </button>
              </div>
            ) : (
              <div className="relative">
                <input
                  className="input-field"
                  type="search"
                  value={patientQuery}
                  onChange={(event) => onPatientQueryChange(event.target.value)}
                  placeholder={t('patientSearchPlaceholder')}
                  autoComplete="off"
                  aria-invalid={!!fieldError('patientId') && !quickCreate}
                />
                <input type="hidden" name="patientId" value={patientIdField} />
                {results.length > 0 ? (
                  <ul className="absolute z-10 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                    {results.map((result) => (
                      <li key={result.id}>
                        <button
                          type="button"
                          className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                          onClick={() => {
                            setPatient(result);
                            setPatientQuery(result.fullName);
                            setResults([]);
                          }}
                        >
                          <span className="font-medium text-slate-900">{result.fullName}</span>
                          <span className="ml-2 text-xs text-slate-500">{result.phoneE164}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                ) : null}
                {fieldError('patientId') && !quickCreate ? (
                  <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                    {fieldError('patientId')}
                  </p>
                ) : null}
              </div>
            )}
          </div>

          {/* Schedule */}
          <div className="grid gap-2 sm:grid-cols-2">
            <div>
              <label className="text-xs uppercase text-slate-500">{t('doctor')}</label>
              <select
                className="input-field"
                name="doctorId"
                defaultValue={initial?.doctorId ?? ''}
                aria-invalid={!!fieldError('doctorId')}
              >
                <option value="">{t('selectDoctor')}</option>
                {doctors.map((doctor) => (
                  <option key={doctor.id} value={doctor.id}>
                    {doctor.fullName}
                  </option>
                ))}
              </select>
              <FieldError fields={fields} name="doctorId" tv={tv} />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('service')}</label>
              <select
                className="input-field"
                name="serviceId"
                defaultValue={initial?.serviceId ?? ''}
                aria-invalid={!!fieldError('serviceId')}
              >
                <option value="">{t('noService')}</option>
                {services.map((service) => (
                  <option key={service.id} value={service.id}>
                    {service.name}
                  </option>
                ))}
              </select>
              <FieldError fields={fields} name="serviceId" tv={tv} />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('date')}</label>
              <input
                className="input-field"
                type="date"
                name="dateKey"
                defaultValue={initial?.dateKey ?? todayKey}
                aria-invalid={!!fieldError('dateKey')}
              />
              <FieldError fields={fields} name="dateKey" tv={tv} />
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="text-xs uppercase text-slate-500">{t('startTime')}</label>
                <input
                  className="input-field"
                  type="time"
                  name="startTime"
                  defaultValue={initial?.startTime ?? '09:00'}
                  aria-invalid={!!fieldError('startTime')}
                />
                <FieldError fields={fields} name="startTime" tv={tv} />
              </div>
              <div>
                <label className="text-xs uppercase text-slate-500">{t('duration')}</label>
                <select
                  className="input-field"
                  name="durationMin"
                  defaultValue={initial?.durationMin ?? 30}
                  aria-invalid={!!fieldError('durationMin')}
                >
                  {DURATIONS.map((minutes) => (
                    <option key={minutes} value={minutes}>
                      {minutes} min
                    </option>
                  ))}
                </select>
                <FieldError fields={fields} name="durationMin" tv={tv} />
              </div>
            </div>
          </div>

          <div>
            <label className="text-xs uppercase text-slate-500">{t('notes')}</label>
            <textarea
              className="input-field"
              name="notes"
              rows={2}
              defaultValue={initial?.notes ?? ''}
              placeholder={t('notesHint')}
              aria-invalid={!!fieldError('notes')}
            />
            <FieldError fields={fields} name="notes" tv={tv} />
          </div>

          {failed && fields ? (
            <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm">
              <p className="font-medium text-red-800">{tc('fixFields')}</p>
              <ul className="mt-1 list-inside list-disc">
                {Object.entries(fields).map(([key, value]) => (
                  <li key={key} className="text-red-700">
                    {tv(value as never)}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <AppointmentError error={state?.ok ? null : state?.error} />

          {otherWarnings.length > 0 ? (
            <ul className="space-y-1 text-sm text-amber-700">
              {otherWarnings.map((key) => (
                <li key={key}>{t(`warning.${key}` as never)}</li>
              ))}
            </ul>
          ) : null}

          {showOverridePrompt ? (
            <div className="rounded-lg border border-amber-300 bg-amber-50 p-3">
              <p className="text-sm font-medium text-amber-900">{t('doubleBookingTitle')}</p>
              <p className="mt-1 text-sm text-amber-800">{t('doubleBookingBody')}</p>
              <div className="mt-3 flex justify-end gap-2">
                <button type="button" className="btn-secondary" onClick={onClose}>
                  {tc('cancel')}
                </button>
                <button
                  type="button"
                  className="btn-primary"
                  onClick={saveAnyway}
                  disabled={pending}
                >
                  {t('saveAnyway')}
                </button>
              </div>
            </div>
          ) : null}

          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={onClose}>
              {tc('cancel')}
            </button>
            <button type="submit" className="btn-primary" disabled={pending}>
              {pending ? tc('saving') : tc('save')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
