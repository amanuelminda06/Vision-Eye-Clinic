'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import { sendDailySummaryEmailAction } from '../actions';

/** OWNER "send end-of-day email" trigger for today's sales summary. */
export default function DailyEmailButton() {
  const t = useTranslations('dashboard');
  const [pending, setPending] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  async function send() {
    setPending(true);
    const result = await sendDailySummaryEmailAction(null, new FormData());
    setPending(false);
    if (result.ok && result.data) {
      setNotice(t('emailSent', { to: result.data.to, mode: result.data.mode }));
      setTimeout(() => setNotice(null), 8000);
    } else {
      setNotice(result.ok ? t('emailFailed') : (result.error ?? 'unknownError'));
    }
  }

  return (
    <div className="no-print flex flex-wrap items-center gap-2">
      <button type="button" className="btn-primary" disabled={pending} onClick={() => void send()}>
        {pending ? t('emailSending') : t('sendDailyEmail')}
      </button>
      {notice ? <span className="text-xs text-slate-500">{notice}</span> : null}
    </div>
  );
}