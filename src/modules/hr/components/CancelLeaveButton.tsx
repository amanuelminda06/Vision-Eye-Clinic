'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import HrError from './HrError';

type Props = {
  action: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
  leaveId: string;
};

export default function CancelLeaveButton({ action, leaveId }: Props) {
  const router = useRouter();
  const t = useTranslations('hr');
  const tc = useTranslations('common');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(action, null);

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  return (
    <form
      action={formAction}
      onSubmit={(event) => {
        if (!window.confirm(t('cancelConfirm'))) event.preventDefault();
      }}
    >
      <input type="hidden" name="leaveId" value={leaveId} />
      <button type="submit" className="text-sm text-red-600 hover:underline" disabled={pending}>
        {pending ? tc('saving') : t('cancelRequest')}
      </button>
      {state && !state.ok ? <HrError error={state.error} /> : null}
    </form>
  );
}
