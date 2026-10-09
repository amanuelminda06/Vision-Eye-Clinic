'use client';

import { useActionState } from 'react';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setStaffActiveAction } from '@/modules/staff/actions';
import type { ActionResult } from '@/lib/action';

type Props = {
  staffId: string;
  active: boolean;
};

export default function StaffRowActions({ staffId, active }: Props) {
  const t = useTranslations('staff');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setStaffActiveAction,
    null,
  );

  function confirmToggle(event: React.MouseEvent<HTMLButtonElement>) {
    if (active && !window.confirm(t('deactivateConfirm'))) event.preventDefault();
  }

  return (
    <div className="flex flex-col items-start gap-1">
      <form action={formAction}>
        <input type="hidden" name="id" value={staffId} />
        <input type="hidden" name="active" value={active ? 'false' : 'true'} />
        <button
          type="submit"
          className="text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline disabled:opacity-50"
          disabled={pending}
          onClick={confirmToggle}
        >
          {active ? t('deactivate') : t('reactivate')}
        </button>
      </form>
      <ActionError error={state && !state.ok ? state.error : undefined} />
    </div>
  );
}
