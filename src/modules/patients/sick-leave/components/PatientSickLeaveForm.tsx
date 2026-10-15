'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { createPatientSickLeaveAction, updatePatientSickLeaveAction } from '../actions';
import type { ActionResult } from '@/lib/action';

type Props = {
  patientId: string;
  route: string;
  editing?: {
    id: string;
    startDate: string;
    endDate: string;
    reason: string | null;
    note: string | null;
  };
  onSuccess?: () => void;
};

export default function PatientSickLeaveForm({ patientId, route, editing, onSuccess }: Props) {
  const router = useRouter();
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const action = editing ? updatePatientSickLeaveAction : createPatientSickLeaveAction;
  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(action, null);
  const failed = !!state && !state.ok;
  const fieldError = (key: string): string | undefined =>
    failed && state.fields?.[key] ? tv(state.fields[key] as never) : undefined;

  useEffect(() => {
    if (state?.ok && state.data && 'id' in state.data) {
      router.refresh();
      onSuccess?.();
    }
  }, [state, router, onSuccess]);

  const today = new Date().toISOString().slice(0, 10);

  return (
    <form action={formAction} className="card space-y-3 p-5">
      {editing ? <input type="hidden" name="id" value={editing.id} /> : null}
      <input type="hidden" name="patientId" value={patientId} />

      <h2 className="text-sm font-semibold text-slate-800">
        {editing ? t('sickLeaveEdit') : t('sickLeaveNew')}
      </h2>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label" htmlFor="sick-start">
            {t('sickStartDate')}
          </label>
          <input
            className="input-field"
            id="sick-start"
            name="startDate"
            type="date"
            max={today}
            defaultValue={editing?.startDate ?? today}
            aria-invalid={!!fieldError('startDate')}
          />
          {fieldError('startDate') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('startDate')}</p>
          ) : null}
        </div>
        <div>
          <label className="label" htmlFor="sick-end">
            {t('sickEndDate')}
          </label>
          <input
            className="input-field"
            id="sick-end"
            name="endDate"
            type="date"
            max={today}
            defaultValue={editing?.endDate ?? today}
            aria-invalid={!!fieldError('endDate')}
          />
          {fieldError('endDate') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('endDate')}</p>
          ) : null}
        </div>
      </div>

      <div>
        <label className="label" htmlFor="sick-reason">
          {t('sickReason')}
        </label>
        <input
          className="input-field"
          id="sick-reason"
          name="reason"
          type="text"
          maxLength={200}
          defaultValue={editing?.reason ?? ''}
          aria-invalid={!!fieldError('reason')}
        />
        {fieldError('reason') ? (
          <p className="mt-1 text-xs font-medium text-red-600">{fieldError('reason')}</p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="sick-note">
          {t('sickNote')}
        </label>
        <textarea
          className="input-field min-h-16"
          id="sick-note"
          name="note"
          rows={3}
          maxLength={2000}
          defaultValue={editing?.note ?? ''}
          aria-invalid={!!fieldError('note')}
        />
        {fieldError('note') ? (
          <p className="mt-1 text-xs font-medium text-red-600">{fieldError('note')}</p>
        ) : null}
      </div>

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : tc('save')}
        </button>
        <button
          type="button"
          className="btn-secondary"
          disabled={pending}
          onClick={() => router.push(route)}
        >
          {tc('cancel')}
        </button>
      </div>
    </form>
  );
}
