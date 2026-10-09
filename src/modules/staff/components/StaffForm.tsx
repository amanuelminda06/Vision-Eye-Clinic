'use client';

import Link from 'next/link';
import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { createStaffAction, updateStaffAction } from '@/modules/staff/actions';
import type { ActionResult } from '@/lib/action';

type StaffInput = {
  id: string;
  fullName: string;
  jobTitle: string;
  isDoctor: boolean;
  specialty: string | null;
  phone: string | null;
  showOnWebsite: boolean;
  photoUrl: string | null;
};

type Props = {
  mode: 'create' | 'edit';
  staff?: StaffInput;
};

export default function StaffForm({ mode, staff }: Props) {
  const router = useRouter();
  const t = useTranslations('staff');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const action = mode === 'create' ? createStaffAction : updateStaffAction;
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(action, null);
  const [isDoctor, setIsDoctor] = useState(staff?.isDoctor ?? false);
  const photoUrl = staff?.photoUrl ?? null;

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  useEffect(() => {
    if (!state?.ok) return;
    router.push('/staff');
    router.refresh();
  }, [state, router]);

  return (
    <form action={formAction} className="card max-w-xl space-y-4 p-6">
      {mode === 'edit' ? <input type="hidden" name="id" value={staff?.id} /> : null}

      <div>
        <label className="label" htmlFor="fullName">
          {t('fullName')}
        </label>
        <input
          className="input-field"
          id="fullName"
          name="fullName"
          type="text"
          required
          defaultValue={staff?.fullName ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('fullName')}
        />
        {fieldError('fullName') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('fullName')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="jobTitle">
          {t('jobTitle')}
        </label>
        <input
          className="input-field"
          id="jobTitle"
          name="jobTitle"
          type="text"
          required
          defaultValue={staff?.jobTitle ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('jobTitle')}
        />
        {fieldError('jobTitle') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('jobTitle')}
          </p>
        ) : null}
      </div>

      <label className="flex items-center gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          name="isDoctor"
          value="true"
          checked={isDoctor}
          onChange={(e) => setIsDoctor(e.target.checked)}
          disabled={pending}
        />
        {t('isDoctor')}
      </label>

      {isDoctor ? (
        <div>
          <label className="label" htmlFor="specialty">
            {t('specialty')}
          </label>
          <input
            className="input-field"
            id="specialty"
            name="specialty"
            type="text"
            defaultValue={staff?.specialty ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('specialty')}
          />
          {fieldError('specialty') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('specialty')}
            </p>
          ) : null}
        </div>
      ) : (
        <input type="hidden" name="specialty" value="" />
      )}

      <div>
        <label className="label" htmlFor="phone">
          {t('phone')}
        </label>
        <input
          className="input-field"
          id="phone"
          name="phone"
          type="tel"
          autoComplete="tel"
          placeholder="09…"
          defaultValue={staff?.phone ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('phone')}
        />
        {fieldError('phone') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('phone')}
          </p>
        ) : null}
      </div>

      <label className="flex items-center gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          name="showOnWebsite"
          value="true"
          defaultChecked={staff?.showOnWebsite ?? false}
          disabled={pending}
        />
        {t('showOnWebsite')}
      </label>

      <div>
        <label className="label" htmlFor="photo">
          {t('photo')}
        </label>
        <div className="flex items-center gap-3">
          {photoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={photoUrl}
              alt=""
              className="h-14 w-14 rounded-full border border-slate-200 object-cover"
            />
          ) : null}
          <input
            id="photo"
            name="photo"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            disabled={pending}
            aria-invalid={!!fieldError('photo')}
          />
        </div>
        <p className="mt-1 text-xs text-slate-500">{t('photoHint')}</p>
        {fieldError('photo') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('photo')}
          </p>
        ) : null}
      </div>

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : mode === 'create' ? t('create') : t('update')}
        </button>
        <Link href="/staff" className="btn-secondary">
          {tc('cancel')}
        </Link>
      </div>
    </form>
  );
}
