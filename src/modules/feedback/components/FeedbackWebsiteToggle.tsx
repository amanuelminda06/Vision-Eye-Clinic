'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setFeedbackWebsiteAction } from '@/modules/feedback/actions';
import type { ActionResult } from '@/lib/action';

type Props = { feedbackId: string; name: string; showOnWebsite: boolean };

/**
 * The CMS "show on website" checkbox. Toggling it submits immediately and the
 * row refreshes once the server confirms — the feedback appears in the public
 * testimonials section (or disappears from it) right away.
 */
export default function FeedbackWebsiteToggle({ feedbackId, name, showOnWebsite }: Props) {
  const router = useRouter();
  const t = useTranslations('feedback');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setFeedbackWebsiteAction,
    null,
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  return (
    <form action={formAction} className="flex items-center gap-2">
      <input type="hidden" name="id" value={feedbackId} />
      <input type="hidden" name="showOnWebsite" value={showOnWebsite ? 'false' : 'true'} />
      <input
        type="checkbox"
        checked={showOnWebsite}
        disabled={pending}
        aria-label={t('showOnWebsiteFor', { name })}
        onChange={(event) => event.currentTarget.form?.requestSubmit()}
        className="h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
      />
      <ActionError error={state && !state.ok ? state.error : undefined} />
    </form>
  );
}
