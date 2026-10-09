'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { LEAVE_TYPES, LEAVE_TYPE_LABEL_KEYS } from '../constants';
import type { StaffOption } from './ShiftFormModal';
import HrError from './HrError';

type Props = {
  action: (
    prev: ActionResult<{ id: string }> | null,
    formData: FormData,
  ) => Promise<ActionResult<{ id: string }>>;
  staffOptions: StaffOption[];
  canForOthers: boolean;
  defaultStaffId: string;
  today: string;
};

export default function LeaveRequestForm({
  action,
  staffOptions,
  canForOthers,
  defaultStaffId,
  today,
}: Props) {
  const router = useRouter();
  const t = useTranslations('hr');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(action, null);

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  const failed = !!state && !state.ok;
  const fieldError = (key: string): string | undefined =>
    failed && state.fields?.[key] ? tv(state.fields[key] as never) : undefined;

  return (
    <form action={formAction} className="card space-y-3 p-5">
      <h2 className="text-sm font-semibold text-slate-800">{t('requestLeave')}</h2>

      {canForOthers ? (
        <div>
          <label className="label" htmlFor="leave-staff">
            {t('staffLabel')}
          </label>
          <select
            className="input-field"
            id="leave-staff"
            name="staffId"
            defaultValue={defaultStaffId}
          >
            <option value="">{t('selectStaff')}</option>
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
      ) : null}

      <div>
        <label className="label" htmlFor="leave-type">
          {t('leaveType')}
        </label>
        <select className="input-field" id="leave-type" name="type" defaultValue="ANNUAL">
          {LEAVE_TYPES.map((type) => (
            <option key={type} value={type}>
              {t(LEAVE_TYPE_LABEL_KEYS[type] as never)}
            </option>
          ))}
        </select>
        {fieldError('type') ? (
          <p className="mt-1 text-xs font-medium text-red-600">{fieldError('type')}</p>
        ) : null}
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label" htmlFor="leave-start">
            {t('startDate')}
          </label>
          <input
            className="input-field"
            id="leave-start"
            name="startDate"
            type="date"
            defaultValue={today}
            aria-invalid={!!fieldError('startDate')}
          />
          {fieldError('startDate') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('startDate')}</p>
          ) : null}
        </div>
        <div>
          <label className="label" htmlFor="leave-end">
            {t('endDate')}
          </label>
          <input
            className="input-field"
            id="leave-end"
            name="endDate"
            type="date"
            defaultValue={today}
            aria-invalid={!!fieldError('endDate')}
          />
          {fieldError('endDate') ? (
            <p className="mt-1 text-xs font-medium text-red-600">{fieldError('endDate')}</p>
          ) : null}
        </div>
      </div>

      <label className="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="halfDay" value="true" />
        {t('halfDay')}
      </label>
      <p className="text-xs text-slate-500">{t('halfDayHint')}</p>

      <div>
        <label className="label" htmlFor="leave-reason">
          {t('reason')}
        </label>
        <textarea
          className="input-field min-h-16"
          id="leave-reason"
          name="reason"
          maxLength={300}
          aria-invalid={!!fieldError('reason')}
        />
        {fieldError('reason') ? (
          <p className="mt-1 text-xs font-medium text-red-600">{fieldError('reason')}</p>
        ) : null}
      </div>

      <HrError error={failed ? state.error : undefined} />

      <button type="submit" className="btn-primary" disabled={pending}>
        {pending ? tc('saving') : t('submitRequest')}
      </button>
    </form>
  );
}
