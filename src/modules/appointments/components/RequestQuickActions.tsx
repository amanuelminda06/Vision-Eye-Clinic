'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { setRequestStatusAction } from '../actions';
import AppointmentError from './AppointmentError';

type Props = { requestId: string; status: string };

/** Non-converting request actions: contacted / declined / spam. */
export default function RequestQuickActions({ requestId, status }: Props) {
  const router = useRouter();
  const t = useTranslations('requests');

  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setRequestStatusAction,
    null,
  );

  useEffect(() => {
    if (state?.ok && !pending) router.refresh();
  }, [state, pending, router]);

  if (status === 'CONVERTED') return null;

  function submit(next: string) {
    if (next === 'DECLINED' || next === 'SPAM') {
      if (!window.confirm(t(next === 'SPAM' ? 'confirmSpam' : 'confirmDecline'))) return;
    }
    const formData = new FormData();
    formData.set('id', requestId);
    formData.set('status', next);
    formAction(formData);
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {status === 'NEW' ? (
        <button
          type="button"
          className="btn-secondary"
          disabled={pending}
          onClick={() => submit('CONTACTED')}
        >
          {t('markContacted')}
        </button>
      ) : null}
      <button
        type="button"
        className="btn-secondary"
        disabled={pending}
        onClick={() => submit('DECLINED')}
      >
        {t('decline')}
      </button>
      <button
        type="button"
        className="btn-secondary"
        disabled={pending}
        onClick={() => submit('SPAM')}
      >
        {t('spam')}
      </button>
      <AppointmentError error={state?.ok ? null : state?.error} />
    </div>
  );
}
