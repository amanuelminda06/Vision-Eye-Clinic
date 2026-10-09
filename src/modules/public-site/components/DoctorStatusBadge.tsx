import { getLocale, getTranslations } from 'next-intl/server';
import type { PublicDoctorStatus } from '@/modules/hr/public-status';
import { formatNextAvailable } from '../format';

const TONES: Record<PublicDoctorStatus, string> = {
  ON_DUTY_NOW: 'bg-emerald-100 text-emerald-800',
  ON_LEAVE: 'bg-amber-100 text-amber-800',
  NOT_ON_DUTY: 'bg-slate-100 text-slate-700',
};

type Props = {
  status: PublicDoctorStatus;
  nextShiftStartsAt: string | null;
  timeZone: string;
  className?: string;
};

/** "On duty now" / "On leave today" / "Next available: Tue 09:00" badge. */
export default async function DoctorStatusBadge({
  status,
  nextShiftStartsAt,
  timeZone,
  className = '',
}: Props) {
  const t = await getTranslations('site');
  const locale = await getLocale();

  let label: string;
  if (status === 'ON_DUTY_NOW') label = t('doctors.availableNow');
  else if (status === 'ON_LEAVE') label = t('doctors.onLeave');
  else if (nextShiftStartsAt) {
    label = t('doctors.nextAvailable', {
      when: formatNextAvailable(nextShiftStartsAt, timeZone, locale),
    });
  } else label = t('doctors.noSchedule');

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${TONES[status]} ${className}`}
    >
      {status === 'ON_DUTY_NOW' ? (
        <span className="h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true" />
      ) : null}
      {label}
    </span>
  );
}
