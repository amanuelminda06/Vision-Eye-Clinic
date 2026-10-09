'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setDoNotMessageAction } from '@/modules/patients/actions';
import type { ActionResult } from '@/lib/action';

type Props = {
  patientId: string;
  doNotMessage: boolean;
  reason: string | null;
};

export default function StopMessagesCard({ patientId, doNotMessage, reason }: Props) {
  const router = useRouter();
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [open, setOpen] = useState(false);
  const [reasonValue, setReasonValue] = useState('');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setDoNotMessageAction,
    null,
  );

  useEffect(() => {
    if (!state?.ok) return;
    // Local state (open/reason) resets through the parent-supplied `key`.
    router.refresh();
  }, [state, router]);

  const error = state && !state.ok && state.fields?.reason ? state.fields.reason : undefined;
  const errorMessage = error ? tv(error as never) : undefined;

  return (
    <form action={formAction} className="card space-y-3 p-4">
      <input type="hidden" name="id" value={patientId} />
      <input type="hidden" name="stop" value={doNotMessage ? 'false' : 'true'} />

      <h2 className="text-sm font-semibold text-slate-800">{t('messagingTitle')}</h2>

      <p className="text-sm text-slate-600">
        {doNotMessage ? (
          <>
            <span className="font-medium text-red-700">{t('dnmActive')}</span>{' '}
            {reason ? <span className="text-slate-500">({reason})</span> : null}
          </>
        ) : (
          t('dnmInactive')
        )}
      </p>

      {open && !doNotMessage ? (
        <div>
          <label className="label" htmlFor="dnmReason">
            {t('stopReasonLabel')}
          </label>
          <textarea
            className="input-field min-h-16"
            id="dnmReason"
            name="reason"
            rows={2}
            required
            value={reasonValue}
            onChange={(event) => setReasonValue(event.target.value)}
            disabled={pending}
          />
          {errorMessage ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {errorMessage}
            </p>
          ) : null}
        </div>
      ) : null}

      <ActionError error={state && !state.ok && !error ? state.error : undefined} />

      <div className="flex flex-wrap items-center gap-3">
        {doNotMessage ? (
          <button type="submit" className="btn-secondary" disabled={pending}>
            {pending ? tc('saving') : t('resumeMessages')}
          </button>
        ) : open ? (
          <>
            <button type="submit" className="btn-danger" disabled={pending}>
              {pending ? tc('saving') : t('stopConfirm')}
            </button>
            <button
              type="button"
              className="btn-secondary"
              onClick={() => setOpen(false)}
              disabled={pending}
            >
              {tc('cancel')}
            </button>
          </>
        ) : (
          <button type="button" className="btn-danger" onClick={() => setOpen(true)}>
            {t('stopMessages')}
          </button>
        )}
      </div>
    </form>
  );
}
