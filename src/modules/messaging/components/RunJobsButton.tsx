'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import { runMessageJobsAction } from '../actions';

/** OWNER "run now" trigger for the scheduled message jobs (cron is external). */
export default function RunJobsButton() {
  const t = useTranslations('messages');
  const [pending, setPending] = useState(false);
  const [summary, setSummary] = useState('');

  async function run() {
    setPending(true);
    const result = await runMessageJobsAction(null, new FormData());
    setPending(false);
    if (result.ok && result.data) {
      const data = result.data;
      setSummary(
        t('jobsSummary', {
          created: data.sameDay + data.birthdays + data.thankYous + data.followUps + data.recalls,
          expired: data.expired,
        }),
      );
    } else if (!result.ok) {
      setSummary(result.error ?? 'unknownError');
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" className="btn-secondary" disabled={pending} onClick={() => void run()}>
        {pending ? t('runningJobs') : t('runJobs')}
      </button>
      {summary ? <span className="text-xs text-slate-500">{summary}</span> : null}
    </div>
  );
}
