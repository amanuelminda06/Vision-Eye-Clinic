'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import HrError from './HrError';

type Props = {
  action: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
  shiftId: string;
  label: string;
};

export default function DeleteShiftButton({ action, shiftId, label }: Props) {
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
      className="inline"
      onSubmit={(event) => {
        if (!window.confirm(t('deleteShiftConfirm'))) event.preventDefault();
      }}
    >
      <input type="hidden" name="id" value={shiftId} />
      <button type="submit" className="text-sm text-red-600 hover:underline" disabled={pending}>
        {pending ? tc('saving') : label}
      </button>
      {state && !state.ok ? <HrError error={state.error} /> : null}
    </form>
  );
}
