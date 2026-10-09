'use client';

import { useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';
import { workingMinutesBetween, type WorkingDay } from '../logic';
import { REQUEST_WAIT_WORKING_MINUTES } from '../constants';

type Props = {
  createdAtMs: number;
  hours: Array<(WorkingDay & { key: string }) | undefined> | undefined;
  timeZone: string;
};

/** How long a website request has been waiting, in CLINIC WORKING minutes. */
export default function RequestAge({ createdAtMs, hours, timeZone }: Props) {
  const t = useTranslations('requests');
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60_000);
    return () => clearInterval(timer);
  }, []);

  const started = new Date(createdAtMs);
  const minutes = workingMinutesBetween(started, new Date(now), hours ?? [], timeZone);
  const urgent = minutes >= REQUEST_WAIT_WORKING_MINUTES;

  return (
    <span
      className={
        urgent
          ? 'inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700'
          : 'inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600'
      }
      title={t('waitingSince', {
        date: new Intl.DateTimeFormat(undefined, {
          dateStyle: 'medium',
          timeStyle: 'short',
          timeZone,
        }).format(started),
      })}
    >
      {t('ageMinutes', { minutes })}
    </span>
  );
}
