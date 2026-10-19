'use client';

import { useActionState, useState } from 'react';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setUserPasswordAction } from '@/modules/users/actions';
import type { ActionResult } from '@/lib/action';

type Props = {
  userId: string;
};

/**
 * Inline panel that lets the owner set a chosen password for a user
 * (as opposed to `resetUserPasswordAction`, which hands out a random temp).
 * Unmounting the panel (parent toggle) resets the action state.
 */
export default function SetPasswordForm({ userId }: Props) {
  const t = useTranslations('users');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setUserPasswordAction,
    null,
  );
  const [mustChange, setMustChange] = useState(false);

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  if (state?.ok) {
    return (
      <p role="status" className="mt-1 text-xs font-medium text-emerald-600">
        {t('passwordSet')}
      </p>
    );
  }

  return (
    <form
      action={formAction}
      className="mt-1 w-64 space-y-2 rounded-lg border border-slate-200 p-2"
    >
      <input type="hidden" name="id" value={userId} />

      <div>
        <label className="label text-xs" htmlFor={`set-password-${userId}`}>
          {t('newPassword')}
        </label>
        <input
          className="input-field text-sm"
          id={`set-password-${userId}`}
          name="password"
          type="password"
          autoComplete="new-password"
          required
          disabled={pending}
          aria-invalid={!!fieldError('password')}
        />
        {fieldError('password') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('password')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label text-xs" htmlFor={`set-password-confirm-${userId}`}>
          {t('confirmPassword')}
        </label>
        <input
          className="input-field text-sm"
          id={`set-password-confirm-${userId}`}
          name="confirmPassword"
          type="password"
          autoComplete="new-password"
          required
          disabled={pending}
          aria-invalid={!!fieldError('confirmPassword')}
        />
        {fieldError('confirmPassword') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('confirmPassword')}
          </p>
        ) : null}
      </div>

      <label className="flex items-center gap-2 text-xs text-slate-600">
        <input
          type="checkbox"
          name="mustChange"
          value="true"
          checked={mustChange}
          disabled={pending}
          onChange={(e) => setMustChange(e.target.checked)}
        />
        {t('mustChangeLabel')}
      </label>

      <p className="text-xs text-slate-500">{t('setPasswordHint')}</p>

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-2">
        <button type="submit" className="btn-secondary px-3 py-1.5 text-xs" disabled={pending}>
          {pending ? tc('saving') : tc('confirm')}
        </button>
      </div>
    </form>
  );
}
