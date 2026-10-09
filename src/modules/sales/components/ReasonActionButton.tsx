'use client';

import { useActionState, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';

type Props = {
  action: (prev: ActionResult | null, formData: FormData) => Promise<ActionResult>;
  saleId: string;
  label: string;
  title: string;
  body?: string;
  variant?: 'primary' | 'danger' | 'secondary';
  /** When false the modal just confirms (no reason textarea). */
  requireReason?: boolean;
  redirectTo?: string;
};

const SALES_ERROR_KEYS = new Set(['notConfirmed', 'alreadyRequested', 'noRequest']);
const COMMON_ERROR_KEYS = new Set(['forbidden', 'unknownError', 'validationError']);

export default function ReasonActionButton({
  action,
  saleId,
  label,
  title,
  body,
  variant = 'secondary',
  requireReason = true,
  redirectTo,
}: Props) {
  const router = useRouter();
  const t = useTranslations('sales');
  const tc = useTranslations('common');
  const [open, setOpen] = useState(false);
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(action, null);

  useEffect(() => {
    if (state?.ok) {
      if (redirectTo) router.push(redirectTo);
      else router.refresh();
    }
  }, [state, router, redirectTo]);

  const buttonClass =
    variant === 'primary' ? 'btn-primary' : variant === 'danger' ? 'btn-danger' : 'btn-secondary';
  const error = state && !state.ok ? state.error : undefined;
  const showModal = open && !state?.ok;

  return (
    <>
      <button type="button" className={buttonClass} onClick={() => setOpen(true)}>
        {label}
      </button>

      {showModal ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label={tc('close')}
            className="absolute inset-0 bg-slate-900/40"
            onClick={() => setOpen(false)}
          />
          <form
            action={formAction}
            className="relative z-10 w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xl"
          >
            <input type="hidden" name="saleId" value={saleId} />
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            {body ? <p className="text-sm text-slate-600">{body}</p> : null}

            {requireReason ? (
              <div>
                <label className="label" htmlFor={`reason-${saleId}`}>
                  {t('reason')}
                </label>
                <textarea
                  className="input-field min-h-20"
                  id={`reason-${saleId}`}
                  name="reason"
                  required
                  minLength={3}
                  maxLength={300}
                  disabled={pending}
                />
              </div>
            ) : null}

            {error ? (
              <p role="alert" className="text-sm font-medium text-red-600">
                {SALES_ERROR_KEYS.has(error)
                  ? t(error as never)
                  : COMMON_ERROR_KEYS.has(error)
                    ? tc(error as never)
                    : null}
              </p>
            ) : null}

            <div className="flex items-center justify-end gap-3 pt-1">
              <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>
                {tc('cancel')}
              </button>
              <button type="submit" className={buttonClass} disabled={pending}>
                {pending ? tc('saving') : label}
              </button>
            </div>
          </form>
        </div>
      ) : null}
    </>
  );
}
