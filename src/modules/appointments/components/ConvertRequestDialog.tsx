'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { convertRequestAction } from '../actions';
import AppointmentError from './AppointmentError';

export type ConvertDoctorOption = { id: string; fullName: string };

type Props = {
  open: boolean;
  onClose: () => void;
  requestId: string;
  patientId: string | null;
  doctors: ConvertDoctorOption[];
};

/** Book the appointment for a website request (matches/creates the patient). */
export default function ConvertRequestDialog({
  open,
  onClose,
  requestId,
  patientId,
  doctors,
}: Props) {
  const router = useRouter();
  const t = useTranslations('requests');
  const tc = useTranslations('common');

  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(convertRequestAction, null);

  useEffect(() => {
    if (state?.ok && !pending) {
      router.refresh();
      onClose();
    }
  }, [state, pending, router, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 className="text-base font-semibold text-slate-900">{t('convertTitle')}</h3>
        <p className="mt-1 text-sm text-slate-600">{t('convertHint')}</p>

        <form action={formAction} className="mt-3 space-y-3">
          <input type="hidden" name="requestId" value={requestId} />
          <input type="hidden" name="patientId" value={patientId ?? ''} />

          <div>
            <label className="text-xs uppercase text-slate-500">{t('assignDoctor')}</label>
            <select className="input-field" name="doctorId" defaultValue="">
              <option value="">{t('selectDoctor')}</option>
              {doctors.map((doctor) => (
                <option key={doctor.id} value={doctor.id}>
                  {doctor.fullName}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="text-xs uppercase text-slate-500">{t('notes')}</label>
            <textarea className="input-field" name="notes" rows={2} placeholder={t('notesHint')} />
          </div>

          <AppointmentError error={state?.ok ? null : state?.error} />

          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={onClose}>
              {tc('cancel')}
            </button>
            <button type="submit" className="btn-primary" disabled={pending}>
              {pending ? tc('saving') : t('convert')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
