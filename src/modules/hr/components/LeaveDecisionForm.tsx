'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import HrError from './HrError';

type Props = {
  leaveId: string;
  approveAction: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
  rejectAction: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
};

export default function LeaveDecisionForm({ leaveId, approveAction, rejectAction }: Props) {
  const router = useRouter();
  const t = useTranslations('hr');
  const tc = useTranslations('common');
  const [note, setNote] = useState('');

  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    async (prev, formData) =>
      String(formData.get('decision')) === 'reject'
        ? rejectAction(prev, formData)
        : approveAction(prev, formData),
    null,
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  return (
    <form action={formAction} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="leaveId" value={leaveId} />
      <input
        className="input-field w-48"
        type="text"
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder={t('decisionNote')}
        maxLength={300}
        aria-label={t('decisionNote')}
      />
      <input type="hidden" name="note" value={note} />
      <button
        type="submit"
        name="decision"
        value="approve"
        className="btn-primary"
        disabled={pending}
      >
        {t('approve')}
      </button>
      <button
        type="submit"
        name="decision"
        value="reject"
        className="btn-secondary"
        disabled={pending}
      >
        {t('reject')}
      </button>
      {state && !state.ok ? <HrError error={state.error} /> : null}
      <span className="text-xs text-slate-400">{tc('optional')}</span>
    </form>
  );
}
