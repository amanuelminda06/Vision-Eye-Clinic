'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { setFollowUpStatusAction } from '../actions';
import AppointmentError from './AppointmentError';

type Props = { followUpId: string; status: string };

/** OPEN follow-ups can be completed or cancelled (from now on). */
export default function FollowUpRowActions({ followUpId, status }: Props) {
  const router = useRouter();
  const t = useTranslations('followups');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setFollowUpStatusAction,
    null,
  );

  useEffect(() => {
    if (state?.ok && !pending) router.refresh();
  }, [state, pending, router]);

  if (status !== 'OPEN') return null;

  function submit(next: string) {
    if (next === 'CANCELLED' && !window.confirm(t('cancelConfirm'))) return;
    const formData = new FormData();
    formData.set('id', followUpId);
    formData.set('status', next);
    formAction(formData);
  }

  return (
    <div className="flex items-center gap-2">
      <button
        type="button"
        className="btn-primary"
        disabled={pending}
        onClick={() => submit('DONE')}
      >
        {t('markDone')}
      </button>
      <button
        type="button"
        className="btn-secondary"
        disabled={pending}
        onClick={() => submit('CANCELLED')}
      >
        {t('cancelFollowUp')}
      </button>
      <AppointmentError error={state?.ok ? null : state?.error} />
    </div>
  );
}
