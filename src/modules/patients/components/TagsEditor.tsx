'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setPatientTagsAction } from '@/modules/patients/actions';
import type { ActionResult } from '@/lib/action';

type Props = {
  patientId: string;
  tags: Array<{ id: string; name: string; color: string }>;
  allTags: Array<{ name: string }>;
};

export default function TagsEditor({ patientId, tags, allTags }: Props) {
  const router = useRouter();
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [value, setValue] = useState(() => tags.map((tag) => tag.name).join(', '));
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setPatientTagsAction,
    null,
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  const fieldError =
    state && !state.ok && state.fields?.tags ? tv(state.fields.tags as never) : undefined;
  const dirty = value !== tags.map((tag) => tag.name).join(', ');

  return (
    <form action={formAction} className="card space-y-3 p-4">
      <input type="hidden" name="patientId" value={patientId} />
      <input type="hidden" name="tags" value={value} />

      <div className="flex flex-wrap items-center gap-2">
        {tags.length === 0 ? (
          <span className="text-sm text-slate-500">{t('noTags')}</span>
        ) : (
          tags.map((tag) => (
            <span
              key={tag.id}
              className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium text-white"
              style={{ backgroundColor: tag.color }}
            >
              {tag.name}
            </span>
          ))
        )}
      </div>

      <div>
        <label className="label" htmlFor="tagsInput">
          {t('tagsTitle')}
        </label>
        <input
          className="input-field"
          id="tagsInput"
          type="text"
          list="patient-tag-options"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          placeholder={t('tagsHint')}
          disabled={pending}
        />
        <datalist id="patient-tag-options">
          {allTags.map((tag) => (
            <option key={tag.name} value={tag.name} />
          ))}
        </datalist>
        <p className="mt-1 text-xs text-slate-500">{t('tagsCreateHint')}</p>
        {fieldError ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError}
          </p>
        ) : null}
      </div>

      <ActionError error={state && !state.ok ? state.error : undefined} />

      <button type="submit" className="btn-secondary" disabled={pending || !dirty}>
        {pending ? tc('saving') : t('saveTags')}
      </button>
    </form>
  );
}
