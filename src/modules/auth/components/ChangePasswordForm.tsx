'use client';

import { useActionState, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { getSession, signIn } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import { changePasswordAction } from '@/modules/auth/actions';
import type { ActionResult } from '@/lib/action';

/**
 * Forced (and voluntary) password change.
 *
 * After a successful change we immediately re-login with the NEW password so
 * the JWT claims refresh (mustChangePassword → false). Without this the old
 * claim would bounce the user back to this page.
 */
export default function ChangePasswordForm() {
  const router = useRouter();
  const t = useTranslations('changePassword');
  const tv = useTranslations('validation');
  const tc = useTranslations('common');
  const [state, action, pending] = useActionState<ActionResult | null, FormData>(
    changePasswordAction,
    null,
  );
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const doneRef = useRef(false);

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const done = !!state?.ok;

  const fieldError = (key: string): string | undefined => {
    const code = fields?.[key];
    return code ? tv(code as never) : undefined;
  };

  useEffect(() => {
    if (!state?.ok || doneRef.current) return;
    doneRef.current = true;
    void (async () => {
      const session = await getSession();
      if (session?.user?.email) {
        await signIn('credentials', {
          email: session.user.email,
          password: newPassword,
          redirect: false,
        });
      }
      router.push('/dashboard');
      router.refresh();
    })();
  }, [state, newPassword, router]);

  return (
    <form action={action} className="space-y-4">
      <div>
        <label className="label" htmlFor="currentPassword">
          {t('current')}
        </label>
        <input
          className="input-field"
          id="currentPassword"
          name="currentPassword"
          type="password"
          autoComplete="current-password"
          required
          disabled={pending || done}
          value={currentPassword}
          onChange={(e) => setCurrentPassword(e.target.value)}
          aria-invalid={!!fieldError('currentPassword')}
        />
        {fieldError('currentPassword') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('currentPassword')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="newPassword">
          {t('next')}
        </label>
        <input
          className="input-field"
          id="newPassword"
          name="newPassword"
          type="password"
          autoComplete="new-password"
          required
          disabled={pending || done}
          value={newPassword}
          onChange={(e) => setNewPassword(e.target.value)}
          aria-invalid={!!fieldError('newPassword')}
        />
        {fieldError('newPassword') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('newPassword')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="confirmPassword">
          {t('confirm')}
        </label>
        <input
          className="input-field"
          id="confirmPassword"
          name="confirmPassword"
          type="password"
          autoComplete="new-password"
          required
          disabled={pending || done}
          value={confirmPassword}
          onChange={(e) => setConfirmPassword(e.target.value)}
          aria-invalid={!!fieldError('confirmPassword')}
        />
        {fieldError('confirmPassword') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('confirmPassword')}
          </p>
        ) : null}
      </div>

      {failed && state.error ? (
        <p role="alert" className="text-sm font-medium text-red-600">
          {state.error === 'validationError' ? tc('validationError') : tc('unknownError')}
        </p>
      ) : null}

      {state?.ok ? (
        <p role="status" className="text-sm font-medium text-emerald-600">
          {t('success')}
        </p>
      ) : null}

      <button type="submit" className="btn-primary w-full" disabled={pending || done}>
        {pending ? tc('saving') : t('submit')}
      </button>
    </form>
  );
}
