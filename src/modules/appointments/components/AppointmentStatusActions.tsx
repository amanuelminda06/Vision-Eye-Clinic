'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { setAppointmentStatusAction } from '../actions';
import { transitionAllowed } from '../logic';
import { APPOINTMENT_STATUSES } from '../constants';
import AppointmentError from './AppointmentError';

type Props = {
  appointmentId: string;
  currentStatus: string;
  canOverride: boolean;
};

export default function AppointmentStatusActions({
  appointmentId,
  currentStatus,
  canOverride,
}: Props) {
  const router = useRouter();
  const t = useTranslations('appointments');
  const tc = useTranslations('common');

  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setAppointmentStatusAction,
    null,
  );
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  useEffect(() => {
    if (state?.ok && !pending) router.refresh();
  }, [state, pending, router]);

  if (!currentStatus) return null;

  const options = APPOINTMENT_STATUSES.filter((next) =>
    transitionAllowed(currentStatus, next, { ownerOverride: canOverride }),
  );

  function submitStatus(next: string) {
    const formData = new FormData();
    formData.set('id', appointmentId);
    formData.set('status', next);
    formAction(formData);
  }

  function onNext(next: string) {
    if (next === 'CANCELLED' || next === 'NO_SHOW') {
      if (next === 'CANCELLED') {
        setCancelOpen(true);
        return;
      }
      if (!window.confirm(t('confirmDestructive'))) return;
    }
    submitStatus(next);
  }

  function submitCancel(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!cancelReason.trim()) return;
    const formData = new FormData();
    formData.set('id', appointmentId);
    formData.set('status', 'CANCELLED');
    formData.set('cancelReason', cancelReason.trim());
    formAction(formData);
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {options.map((next) => (
        <button
          key={next}
          type="button"
          className={
            next === 'CANCELLED'
              ? 'btn-danger'
              : next === 'NO_SHOW'
                ? 'btn-secondary'
                : 'btn-primary'
          }
          disabled={pending}
          onClick={() => onNext(next)}
        >
          {t(`action.${next}` as never)}
        </button>
      ))}

      {cancelOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">{t('cancelAppointment')}</h3>
            <form onSubmit={submitCancel} className="mt-3 space-y-3">
              <textarea
                className="input-field"
                value={cancelReason}
                onChange={(event) => setCancelReason(event.target.value)}
                rows={3}
                placeholder={t('cancelReasonRequired')}
                required
              />
              <AppointmentError error={state?.ok ? null : state?.error} />
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setCancelOpen(false)}
                >
                  {tc('cancel')}
                </button>
                <button
                  type="submit"
                  className="btn-danger"
                  disabled={pending || !cancelReason.trim()}
                >
                  {t('action.CANCELLED')}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
