'use client';

import Link from 'next/link';
import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { createServiceAction, updateServiceAction } from '@/modules/services/actions';
import { CATEGORY_LABEL_KEYS, SERVICE_CATEGORIES } from '@/modules/services/categories';
import type { ActionResult } from '@/lib/action';

type Values = {
  id: string;
  nameEn: string;
  nameAm: string | null;
  category: string;
  currentPrice: string;
  isPublic: boolean;
  publicDescriptionEn: string | null;
  publicDescriptionAm: string | null;
  receiptLabelEn: string | null;
  receiptLabelAm: string | null;
  displayOrder: number;
  imageUrl: string | null;
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

  const [isPublic, setIsPublic] = useState(service?.isPublic ?? true);
  const [publicDescriptionAm, setPublicDescriptionAm] = useState(service?.publicDescriptionAm ?? '');

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
        <label className="label" htmlFor="nameEn">
          {t('nameEn')}
        </label>
        <input
          className="input-field"
          id="nameEn"
          name="nameEn"
          type="text"
          required
          autoComplete="off"
          defaultValue={service?.nameEn ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('nameEn')}
        />
        <p className="mt-1 text-xs text-slate-500">{t('nameEnHint')}</p>
        {fieldError('nameEn') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('nameEn')}
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

      <fieldset className="space-y-3 rounded-lg border border-slate-200 p-4">
        <legend className="px-1 text-sm font-semibold text-slate-800">Receipt</legend>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="receiptLabelEn">
              {t('receiptLabelEn')}
            </label>
            <input
              className="input-field"
              id="receiptLabelEn"
              name="receiptLabelEn"
              type="text"
              autoComplete="off"
              defaultValue={service?.receiptLabelEn ?? ''}
              disabled={pending}
              aria-invalid={!!fieldError('receiptLabelEn')}
            />
            {fieldError('receiptLabelEn') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('receiptLabelEn')}
              </p>
            ) : null}
          </div>
          <div>
            <label className="label" htmlFor="receiptLabelAm">
              {t('receiptLabelAm')}
            </label>
            <input
              className="input-field"
              id="receiptLabelAm"
              name="receiptLabelAm"
              type="text"
              autoComplete="off"
              defaultValue={service?.receiptLabelAm ?? ''}
              disabled={pending}
              aria-invalid={!!fieldError('receiptLabelAm')}
            />
            {fieldError('receiptLabelAm') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('receiptLabelAm')}
              </p>
            ) : null}
          </div>
        </div>
        <p className="text-xs text-slate-500">{t('receiptLabelsHint')}</p>
      </fieldset>

      <div>
        <label className="label" htmlFor="displayOrder">
          {t('displayOrder')}
        </label>
        <input
          className="input-field w-full sm:w-40"
          id="displayOrder"
          name="displayOrder"
          type="number"
          inputMode="numeric"
          min={0}
          step={1}
          autoComplete="off"
          defaultValue={service?.displayOrder ?? 0}
          disabled={pending}
          aria-invalid={!!fieldError('displayOrder')}
        />
        <p className="mt-1 text-xs text-slate-500">{t('displayOrderHint')}</p>
      </div>

      <fieldset className="space-y-4 rounded-lg border border-slate-200 p-4">
        <legend className="px-1 text-sm font-semibold text-slate-800">{t('publicTitle')}</legend>
        <label className="flex items-start gap-3 text-sm text-slate-700">
          <input
            type="checkbox"
            name="isPublic"
            value="true"
            checked={isPublic}
            onChange={(event) => setIsPublic(event.target.checked)}
            disabled={pending}
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
          />
          <span>
            {t('publicLabel')}
            <span className="mt-0.5 block text-xs text-slate-500">{t('publicHint')}</span>
          </span>
        </label>

        <div>
          <label className="label" htmlFor="imageUrl">
            {t('imageUrl')}
          </label>
          <div className="flex items-center gap-3">
            {service?.imageUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={service.imageUrl}
                alt=""
                className="h-14 w-14 rounded border border-slate-200 object-cover"
              />
            ) : null}
            <input
              id="imageUrl"
              name="imageUrl"
              type="text"
              autoComplete="off"
              placeholder="/services/general-eye-care.png"
              defaultValue={service?.imageUrl ?? ''}
              disabled={pending}
              aria-invalid={!!fieldError('imageUrl')}
            />
          </div>
          <p className="mt-1 text-xs text-slate-500">{t('imageUrlHint')}</p>
          {fieldError('imageUrl') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('imageUrl')}
            </p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor="image">
            {t('image')}
          </label>
          <div className="flex items-center gap-3">
            <input
              id="image"
              name="image"
              type="file"
              accept="image/png,image/jpeg,image/webp"
              disabled={pending}
              aria-invalid={!!fieldError('image')}
            />
          </div>
          <p className="mt-1 text-xs text-slate-500">{t('imageHint')}</p>
          {fieldError('image') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('image')}
            </p>
          ) : null}
        </div>

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
            value={publicDescriptionAm}
            onChange={(event) => setPublicDescriptionAm(event.target.value)}
            disabled={pending}
            aria-invalid={!!fieldError('publicDescriptionAm')}
          />
          {isPublic && !publicDescriptionAm.trim() ? (
            <p className="mt-1 inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
              {t('toBeProvided')}
            </p>
          ) : null}
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