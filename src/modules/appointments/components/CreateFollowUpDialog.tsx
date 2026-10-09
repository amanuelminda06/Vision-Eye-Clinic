'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { createFollowUpAction } from '../actions';
import { FOLLOW_UP_PRESETS, type FollowUpPreset } from '../constants';
import AppointmentError from './AppointmentError';

type Props = {
  open: boolean;
  onClose: () => void;
  patientId: string;
  appointmentId?: string;
};

/** "Create follow-up" offered when completing an appointment (preset due dates). */
export default function CreateFollowUpDialog({ open, onClose, patientId, appointmentId }: Props) {
  const router = useRouter();
  const t = useTranslations('appointments');
  const tc = useTranslations('common');

  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(createFollowUpAction, null);
  const [preset, setPreset] = useState<FollowUpPreset>('2w');

  useEffect(() => {
    if (state?.ok && !pending) {
      router.refresh();
      onClose();
    }
  }, [state, pending, router, onClose]);

  if (!open) return null;

  const todayKey = new Date().toISOString().slice(0, 10);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 className="text-base font-semibold text-slate-900">{t('createFollowUp')}</h3>

        <form action={formAction} className="mt-3 space-y-3">
          <input type="hidden" name="patientId" value={patientId} />
          <input type="hidden" name="appointmentId" value={appointmentId ?? ''} />

          <div>
            <label className="text-xs uppercase text-slate-500">{t('presetLabel')}</label>
            <div className="mt-1 flex flex-wrap gap-2">
              {FOLLOW_UP_PRESETS.map((value) => (
                <button
                  key={value}
                  type="button"
                  className={preset === value ? 'btn-primary' : 'btn-secondary'}
                  onClick={() => setPreset(value)}
                >
                  {t(`preset.${value}` as never)}
                </button>
              ))}
            </div>
          </div>

          {preset === 'custom' ? (
            <div>
              <label className="text-xs uppercase text-slate-500">{t('dueDate')}</label>
              <input className="input-field" type="date" name="dueDate" defaultValue={todayKey} />
            </div>
          ) : null}

          <div>
            <label className="text-xs uppercase text-slate-500">{t('reason')}</label>
            <input
              className="input-field"
              name="reason"
              placeholder={t('reasonHint')}
              autoComplete="off"
            />
          </div>
          <input type="hidden" name="preset" value={preset} />

          <AppointmentError error={state?.ok ? null : state?.error} />

          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={onClose}>
              {tc('cancel')}
            </button>
            <button type="submit" className="btn-primary" disabled={pending}>
              {pending ? tc('saving') : t('createFollowUp')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
