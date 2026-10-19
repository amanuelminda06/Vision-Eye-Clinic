'use client';

import { useActionState, useState } from 'react';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { resetUserPasswordAction, setUserActiveAction } from '@/modules/users/actions';
import SetPasswordForm from './SetPasswordForm';
import type { ActionResult } from '@/lib/action';

type Props = {
  userId: string;
  active: boolean;
  isSelf: boolean;
};

export default function UserRowActions({ userId, active, isSelf }: Props) {
  const tu = useTranslations('users');
  const tc = useTranslations('common');
  const [activeState, activeAction, activePending] = useActionState<ActionResult | null, FormData>(
    setUserActiveAction,
    null,
  );
  const [resetState, resetAction, resetPending] = useActionState<
    ActionResult<{ tempPassword: string }> | null,
    FormData
  >(resetUserPasswordAction, null);
  const [copied, setCopied] = useState(false);
  const [showSetPassword, setShowSetPassword] = useState(false);

  const tempPassword = resetState?.ok ? resetState.data?.tempPassword : undefined;

  function confirmDeactivate(event: React.MouseEvent<HTMLButtonElement>) {
    if (!window.confirm(tu('deactivateConfirm'))) event.preventDefault();
  }
  function confirmReset(event: React.MouseEvent<HTMLButtonElement>) {
    if (!window.confirm(tu('resetConfirm'))) event.preventDefault();
  }

  async function copyTemp() {
    if (!tempPassword) return;
    try {
      await navigator.clipboard.writeText(tempPassword);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard unavailable — the password is still selectable in the panel */
    }
  }

  return (
    <div className="flex flex-col items-start gap-1">
      <form action={activeAction}>
        <input type="hidden" name="id" value={userId} />
        <input type="hidden" name="active" value={active ? 'false' : 'true'} />
        <button
          type="submit"
          className="text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline disabled:opacity-50"
          disabled={activePending || (isSelf && active)}
          title={isSelf && active ? tc('cannotDeactivateSelf') : undefined}
          onClick={confirmDeactivate}
        >
          {active ? tu('deactivate') : tu('reactivate')}
        </button>
      </form>

      <form action={resetAction}>
        <input type="hidden" name="id" value={userId} />
        <button
          type="submit"
          className="text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline disabled:opacity-50"
          disabled={resetPending}
          onClick={confirmReset}
        >
          {tu('resetPassword')}
        </button>
      </form>

      <button
        type="button"
        className="text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline"
        aria-expanded={showSetPassword}
        onClick={() => setShowSetPassword((open) => !open)}
      >
        {tu('setPassword')}
      </button>
      {showSetPassword ? <SetPasswordForm userId={userId} /> : null}

      <ActionError error={activeState && !activeState.ok ? activeState.error : undefined} />
      <ActionError error={resetState && !resetState.ok ? resetState.error : undefined} />

      {tempPassword ? (
        <div className="mt-1 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs">
          <div className="font-medium text-amber-800">{tu('tempPassword')}</div>
          <div className="mt-1 font-mono text-[13px] text-amber-900 select-all">{tempPassword}</div>
          <div className="mt-1 flex items-center gap-2">
            <button
              type="button"
              className="text-amber-700 underline underline-offset-2"
              onClick={() => void copyTemp()}
            >
              {copied ? tc('copied') : tc('copy')}
            </button>
          </div>
          <p className="mt-1 text-amber-700">{tu('tempPasswordHint')}</p>
        </div>
      ) : null}
    </div>
  );
}
