'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { deleteFeedbackAction } from '@/modules/feedback/actions';
import type { ActionResult } from '@/lib/action';

type Props = { feedbackId: string; name: string };

/** Deletes one feedback entry after an explicit confirmation. */
export default function DeleteFeedbackButton({ feedbackId, name }: Props) {
  const router = useRouter();
  const t = useTranslations('feedback');
  const tc = useTranslations('common');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    deleteFeedbackAction,
    null,
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  return (
    <form
      action={formAction}
      onSubmit={(event) => {
        if (!window.confirm(t('deleteConfirm', { name }))) event.preventDefault();
      }}
      className="inline-flex items-center gap-2"
    >
      <input type="hidden" name="id" value={feedbackId} />
      <ActionError error={state && !state.ok ? state.error : undefined} />
      <button type="submit" disabled={pending} className="btn-danger px-3 py-1.5 text-xs">
        {pending ? tc('saving') : tc('delete')}
      </button>
    </form>
  );
}
