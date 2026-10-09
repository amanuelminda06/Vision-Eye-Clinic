'use client';

import Link from 'next/link';
import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { createUserAction, updateUserAction } from '@/modules/users/actions';
import type { ActionResult } from '@/lib/action';

const ROLE_OPTIONS = [
  { value: 'OWNER', labelKey: 'roleOwner' },
  { value: 'RECEPTION', labelKey: 'roleReception' },
  { value: 'STAFF', labelKey: 'roleStaff' },
] as const;

type Props = {
  mode: 'create' | 'edit';
  user?: { id: string; name: string; email: string; role: string; staffId: string | null };
  staffOptions: { id: string; fullName: string }[];
};

export default function UserForm({ mode, user, staffOptions }: Props) {
  const router = useRouter();
  const t = useTranslations('users');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const action = mode === 'create' ? createUserAction : updateUserAction;
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(action, null);

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  useEffect(() => {
    if (!state?.ok) return;
    router.push('/users');
    router.refresh();
  }, [state, router]);

  return (
    <form action={formAction} className="card max-w-xl space-y-4 p-6">
      {mode === 'edit' ? <input type="hidden" name="id" value={user?.id} /> : null}

      <div>
        <label className="label" htmlFor="name">
          {tc('name')}
        </label>
        <input
          className="input-field"
          id="name"
          name="name"
          type="text"
          required
          defaultValue={user?.name ?? ''}
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
        <label className="label" htmlFor="email">
          {tc('email')}
        </label>
        <input
          className="input-field"
          id="email"
          name="email"
          type="email"
          autoComplete="off"
          required
          defaultValue={user?.email ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('email')}
        />
        {fieldError('email') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('email')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="role">
          {tc('role')}
        </label>
        <select
          className="input-field"
          id="role"
          name="role"
          required
          defaultValue={user?.role ?? 'STAFF'}
          disabled={pending}
          aria-invalid={!!fieldError('role')}
        >
          {ROLE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {tc(option.labelKey as never)}
            </option>
          ))}
        </select>
        {fieldError('role') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('role')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="staffId">
          {t('staffLink')}
        </label>
        <select
          className="input-field"
          id="staffId"
          name="staffId"
          defaultValue={user?.staffId ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('staffId')}
        >
          <option value="">{t('noStaff')}</option>
          {staffOptions.map((staff) => (
            <option key={staff.id} value={staff.id}>
              {staff.fullName}
            </option>
          ))}
        </select>
        {fieldError('staffId') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('staffId')}
          </p>
        ) : null}
      </div>

      {mode === 'create' ? (
        <div>
          <label className="label" htmlFor="password">
            {t('password')}
          </label>
          <input
            className="input-field"
            id="password"
            name="password"
            type="password"
            autoComplete="new-password"
            required
            disabled={pending}
            aria-invalid={!!fieldError('password')}
          />
          <p className="mt-1 text-xs text-slate-500">{t('passwordHint')}</p>
          {fieldError('password') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('password')}
            </p>
          ) : null}
        </div>
      ) : null}

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : mode === 'create' ? t('create') : t('update')}
        </button>
        <Link href="/users" className="btn-secondary">
          {tc('cancel')}
        </Link>
      </div>
    </form>
  );
}
