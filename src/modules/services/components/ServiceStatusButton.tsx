'use client';

import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { setServiceActiveAction } from '@/modules/services/actions';
import type { ActionResult } from '@/lib/action';

type Props = { serviceId: string; active: boolean };

export default function ServiceStatusButton({ serviceId, active }: Props) {
  const router = useRouter();
  const t = useTranslations('services');
  const tc = useTranslations('common');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    setServiceActiveAction,
    null,
  );

  useEffect(() => {
    if (state?.ok) router.refresh();
  }, [state, router]);

  const label = active ? t('deactivate') : t('reactivate');
  const confirmText = active ? t('deactivateConfirm') : t('reactivateConfirm');

  return (
    <form
      action={formAction}
      onSubmit={(event) => {
        if (!window.confirm(confirmText)) event.preventDefault();
      }}
      className="card flex flex-wrap items-center justify-between gap-3 p-4"
    >
      <input type="hidden" name="id" value={serviceId} />
      <input type="hidden" name="active" value={active ? 'false' : 'true'} />

      <p className="text-sm text-slate-600">{active ? t('activeHint') : t('inactiveHint')}</p>

      <div className="flex items-center gap-3">
        <ActionError error={state && !state.ok ? state.error : undefined} />
        <button type="submit" className="btn-danger" disabled={pending}>
          {pending ? tc('saving') : label}
        </button>
      </div>
    </form>
  );
}
