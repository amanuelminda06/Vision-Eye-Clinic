'use client';

import Link from 'next/link';
import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { createServiceAction, updateServiceAction } from '@/modules/services/actions';
import { CATEGORY_LABEL_KEYS, SERVICE_CATEGORIES } from '@/modules/services/categories';
import type { ActionResult } from '@/lib/action';

type Values = {
  id: string;
  name: string;
  nameAm: string | null;
  category: string;
  currentPrice: string;
  isPublic: boolean;
  publicDescriptionEn: string | null;
  publicDescriptionAm: string | null;
};

type Props = {
  mode: 'create' | 'edit';
  service?: Values;
  route: string;
};

export default function ServiceForm({ mode, service, route }: Props) {
  const router = useRouter();
  const t = useTranslations('services');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const action = mode === 'create' ? createServiceAction : updateServiceAction;
  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string }> | null,
    FormData
  >(action, null);

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  useEffect(() => {
    if (state?.ok) {
      router.push('/dashboard/services');
      router.refresh();
    }
  }, [state, router]);

  return (
    <form action={formAction} className="card max-w-2xl space-y-4 p-6">
      {mode === 'edit' ? <input type="hidden" name="id" value={service?.id} /> : null}

      <div>
        <label className="label" htmlFor="name">
          {t('name')}
        </label>
        <input
          className="input-field"
          id="name"
          name="name"
          type="text"
          required
          autoComplete="off"
          defaultValue={service?.name ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('name')}
        />
        {fieldError('name') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('name')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="nameAm">
          {t('nameAm')}
        </label>
        <input
          className="input-field"
          id="nameAm"
          name="nameAm"
          type="text"
          autoComplete="off"
          defaultValue={service?.nameAm ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('nameAm')}
        />
        <p className="mt-1 text-xs text-slate-500">{t('nameAmHint')}</p>
        {fieldError('nameAm') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('nameAm')}
          </p>
        ) : null}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="category">
            {t('category')}
          </label>
          <select
            className="input-field"
            id="category"
            name="category"
            required
            defaultValue={service?.category ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('category')}
          >
            <option value="" disabled>
              {t('chooseCategory')}
            </option>
            {SERVICE_CATEGORIES.map((category) => (
              <option key={category} value={category}>
                {t(CATEGORY_LABEL_KEYS[category] as never)}
              </option>
            ))}
          </select>
          {fieldError('category') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('category')}
            </p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor="currentPrice">
            {t('currentPrice')}
          </label>
          <input
            className="input-field"
            id="currentPrice"
            name="currentPrice"
            type="text"
            inputMode="decimal"
            placeholder="0.00"
            required
            autoComplete="off"
            defaultValue={service?.currentPrice ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('currentPrice')}
          />
          <p className="mt-1 text-xs text-slate-500">{t('priceHint')}</p>
          {fieldError('currentPrice') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('currentPrice')}
            </p>
          ) : null}
        </div>
      </div>

      {mode === 'edit' ? <p className="text-xs text-slate-500">{t('priceChangeNote')}</p> : null}

      <fieldset className="space-y-4 rounded-lg border border-slate-200 p-4">
        <legend className="px-1 text-sm font-semibold text-slate-800">{t('publicTitle')}</legend>
        <label className="flex items-start gap-3 text-sm text-slate-700">
          <input
            type="checkbox"
            name="isPublic"
            value="true"
            defaultChecked={service?.isPublic ?? false}
            disabled={pending}
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
          />
          <span>
            {t('publicLabel')}
            <span className="mt-0.5 block text-xs text-slate-500">{t('publicHint')}</span>
          </span>
        </label>

        <div>
          <label className="label" htmlFor="publicDescriptionEn">
            {t('publicDescriptionEn')}
          </label>
          <textarea
            className="input-field"
            id="publicDescriptionEn"
            name="publicDescriptionEn"
            rows={3}
            maxLength={2000}
            defaultValue={service?.publicDescriptionEn ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('publicDescriptionEn')}
          />
          <p className="mt-1 text-xs text-slate-500">{t('publicDescriptionHint')}</p>
          {fieldError('publicDescriptionEn') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('publicDescriptionEn')}
            </p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor="publicDescriptionAm">
            {t('publicDescriptionAm')}
          </label>
          <textarea
            className="input-field"
            id="publicDescriptionAm"
            name="publicDescriptionAm"
            rows={3}
            maxLength={2000}
            defaultValue={service?.publicDescriptionAm ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('publicDescriptionAm')}
          />
          {fieldError('publicDescriptionAm') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('publicDescriptionAm')}
            </p>
          ) : null}
        </div>
      </fieldset>

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : mode === 'create' ? t('create') : tc('save')}
        </button>
        <Link href={route} className="btn-secondary">
          {tc('cancel')}
        </Link>
      </div>
    </form>
  );
}
