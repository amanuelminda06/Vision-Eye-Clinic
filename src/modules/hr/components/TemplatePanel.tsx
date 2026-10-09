'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import type { TemplatePreview } from '../index';
import type { StaffOption } from './ShiftFormModal';
import HrError from './HrError';

type Props = {
  staffOptions: StaffOption[];
  weekStart: string;
  applyAction: (
    prev: ActionResult<TemplatePreview> | null,
    formData: FormData,
  ) => Promise<ActionResult<TemplatePreview>>;
  previewAction: typeof import('../actions').previewTemplateAction;
  copyAction: (
    prev: ActionResult<TemplatePreview> | null,
    formData: FormData,
  ) => Promise<ActionResult<TemplatePreview>>;
};

export default function TemplatePanel({
  staffOptions,
  weekStart,
  applyAction,
  previewAction,
  copyAction,
}: Props) {
  const router = useRouter();
  const t = useTranslations('hr');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');

  const [applyState, applyFormAction, applying] = useActionState<
    ActionResult<TemplatePreview> | null,
    FormData
  >(applyAction, null);
  const [previewState, previewFormAction, previewing] = useActionState<
    ActionResult<TemplatePreview> | null,
    FormData
  >(previewAction, null);
  const [copyState, copyFormAction, copying] = useActionState<
    ActionResult<TemplatePreview> | null,
    FormData
  >(copyAction, null);

  useEffect(() => {
    if (applyState?.ok || copyState?.ok) router.refresh();
  }, [applyState, copyState, router]);

  const preview = previewState?.ok ? (previewState.data ?? null) : null;
  const applyFailed = !!applyState && !applyState.ok;
  const previewFailed = !!previewState && !previewState.ok;

  return (
    <section className="card space-y-4 p-5">
      <h2 className="text-sm font-semibold text-slate-800">{t('templates')}</h2>

      <form action={applyFormAction} className="flex flex-wrap items-end gap-3">
        <div>
          <label className="label" htmlFor="template-staff">
            {t('staffLabel')}
          </label>
          <select className="input-field" id="template-staff" name="staffId" defaultValue="">
            <option value="">{t('allStaff')}</option>
            {staffOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.fullName}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="label" htmlFor="template-from">
            {t('from')}
          </label>
          <input
            className="input-field"
            id="template-from"
            name="fromKey"
            type="date"
            defaultValue={weekStart}
            aria-invalid={!!(applyFailed && applyState.fields?.fromKey)}
          />
        </div>
        <div>
          <label className="label" htmlFor="template-to">
            {t('to')}
          </label>
          <input
            className="input-field"
            id="template-to"
            name="toKey"
            type="date"
            defaultValue={weekStart}
            aria-invalid={!!(applyFailed && applyState.fields?.toKey)}
          />
        </div>
        <button
          type="submit"
          className="btn-secondary"
          formAction={previewFormAction}
          disabled={previewing || applying}
        >
          {previewing ? tc('loading') : t('previewTemplate')}
        </button>
        <button type="submit" className="btn-primary" disabled={applying || previewing}>
          {applying ? tc('saving') : t('applyTemplate')}
        </button>
      </form>

      {applyFailed && applyState.fields?.toKey ? (
        <p className="text-xs font-medium text-red-600">{tv(applyState.fields.toKey as never)}</p>
      ) : applyFailed ? (
        <HrError error={applyState.error} />
      ) : null}
      {previewFailed && previewState.error ? <HrError error={previewState.error} /> : null}

      {preview ? (
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
          {t('previewResult', { count: preview.count, skipped: preview.skipped })}
        </p>
      ) : null}

      <form
        action={copyFormAction}
        className="flex items-center gap-3 border-t border-slate-100 pt-3"
      >
        <input type="hidden" name="weekStart" value={weekStart} />
        <button type="submit" className="btn-secondary" disabled={copying}>
          {copying ? tc('saving') : t('copyLastWeek')}
        </button>
        <span className="text-xs text-slate-500">{t('copyLastWeekHint')}</span>
      </form>
      {copyState && !copyState.ok ? <HrError error={copyState.error} /> : null}
    </section>
  );
}
