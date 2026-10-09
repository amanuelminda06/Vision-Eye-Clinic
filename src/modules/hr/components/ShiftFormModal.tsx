'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import HrError from './HrError';

export type StaffOption = {
  id: string;
  fullName: string;
  isDoctor: boolean;
  specialty: string | null;
};

type Props = {
  action: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
  staffOptions: StaffOption[];
  defaultDateKey: string;
  defaultStaffId?: string;
  /** Present when editing an existing shift. */
  shift?: {
    id: string;
    staffId: string;
    dateKey: string;
    startTime: string;
    endTime: string;
    note: string;
  };
  label: string;
  variant?: 'primary' | 'secondary' | 'link';
};

export default function ShiftFormModal({
  action,
  staffOptions,
  defaultDateKey,
  defaultStaffId,
  shift,
  label,
  variant = 'secondary',
}: Props) {
  const [open, setOpen] = useState(false);
  const buttonClass =
    variant === 'primary'
      ? 'btn-primary'
      : variant === 'link'
        ? 'text-sm text-slate-600 hover:underline'
        : 'btn-secondary';

  return (
    <>
      <button type="button" className={buttonClass} onClick={() => setOpen(true)}>
        {label}
      </button>
      {open ? (
        <Dialog
          action={action}
          staffOptions={staffOptions}
          defaultDateKey={defaultDateKey}
          defaultStaffId={defaultStaffId}
          shift={shift}
          label={label}
          onDone={() => setOpen(false)}
        />
      ) : null}
    </>
  );
}

type DialogProps = Omit<Props, 'variant'> & { onDone: () => void };

function Dialog({
  action,
  staffOptions,
  defaultDateKey,
  defaultStaffId,
  shift,
  label,
  onDone,
}: DialogProps) {
  const router = useRouter();
  const t = useTranslations('hr');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(action, null);

  useEffect(() => {
    if (state?.ok) {
      router.refresh();
      onDone();
    }
  }, [state, router, onDone]);

  const failed = !!state && !state.ok;
  const fieldError = (key: string): string | undefined =>
    failed && state.fields?.[key] ? tv(state.fields[key] as never) : undefined;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button
        type="button"
        aria-label={tc('close')}
        className="absolute inset-0 bg-slate-900/40"
        onClick={onDone}
      />
      <form
        action={formAction}
        className="relative z-10 w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xl"
      >
        <h2 className="text-sm font-semibold text-slate-900">{label}</h2>
        {shift ? <input type="hidden" name="id" value={shift.id} /> : null}

        <div>
          <label className="label" htmlFor={`shift-staff-${shift?.id ?? 'new'}`}>
            {t('staffLabel')}
          </label>
          <select
            className="input-field"
            id={`shift-staff-${shift?.id ?? 'new'}`}
            name="staffId"
            defaultValue={shift?.staffId ?? defaultStaffId ?? staffOptions[0]?.id ?? ''}
            aria-invalid={!!fieldError('staffId')}
          >
            {staffOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.fullName}
              </option>
            ))}
          </select>
          {fieldError('staffId') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('staffId')}</p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor={`shift-date-${shift?.id ?? 'new'}`}>
            {t('date')}
          </label>
          <input
            className="input-field"
            id={`shift-date-${shift?.id ?? 'new'}`}
            name="dateKey"
            type="date"
            defaultValue={shift?.dateKey ?? defaultDateKey}
            aria-invalid={!!fieldError('dateKey')}
          />
          {fieldError('dateKey') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('dateKey')}</p>
          ) : null}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label" htmlFor={`shift-start-${shift?.id ?? 'new'}`}>
              {t('startTime')}
            </label>
            <input
              className="input-field"
              id={`shift-start-${shift?.id ?? 'new'}`}
              name="startTime"
              type="time"
              defaultValue={shift?.startTime ?? '09:00'}
              aria-invalid={!!fieldError('startTime')}
            />
            {fieldError('startTime') ? (
              <p className="mt-1 text-xs font-medium text-red-600">{fieldError('startTime')}</p>
            ) : null}
          </div>
          <div>
            <label className="label" htmlFor={`shift-end-${shift?.id ?? 'new'}`}>
              {t('endTime')}
            </label>
            <input
              className="input-field"
              id={`shift-end-${shift?.id ?? 'new'}`}
              name="endTime"
              type="time"
              defaultValue={shift?.endTime ?? '17:00'}
              aria-invalid={!!fieldError('endTime')}
            />
            {fieldError('endTime') ? (
              <p className="mt-1 text-xs font-medium text-red-600">{fieldError('endTime')}</p>
            ) : null}
          </div>
        </div>
        <p className="text-xs text-slate-500">{t('overnightHint')}</p>

        <div>
          <label className="label" htmlFor={`shift-note-${shift?.id ?? 'new'}`}>
            {t('note')}
          </label>
          <input
            className="input-field"
            id={`shift-note-${shift?.id ?? 'new'}`}
            name="note"
            type="text"
            maxLength={200}
            defaultValue={shift?.note ?? ''}
            aria-invalid={!!fieldError('note')}
          />
          {fieldError('note') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('note')}</p>
          ) : null}
        </div>

        <HrError error={failed ? state.error : undefined} />

        <div className="flex items-center justify-end gap-3 pt-1">
          <button type="button" className="btn-secondary" onClick={onDone}>
            {tc('cancel')}
          </button>
          <button type="submit" className="btn-primary" disabled={pending}>
            {pending ? tc('saving') : tc('save')}
          </button>
        </div>
      </form>
    </div>
  );
}
