import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatMoney } from '@/lib/money';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom, settingsFromMap } from '@/modules/settings';
import { getDashboardData } from '@/modules/dashboard';
import { type PublicDoctorStatus } from '@/modules/hr';
import type { Role } from '@prisma/client';

const QUICK_LINKS: Array<{ href: string; labelKey: string; roles: Role[] }> = [
  { href: '/patients', labelKey: 'patients', roles: ['OWNER', 'RECEPTION'] },
  { href: '/sales/new', labelKey: 'newSale', roles: ['OWNER', 'RECEPTION'] },
  { href: '/sales', labelKey: 'sales', roles: ['OWNER', 'RECEPTION'] },
  { href: '/appointments', labelKey: 'appointments', roles: ['OWNER', 'RECEPTION'] },
  { href: '/requests', labelKey: 'requests', roles: ['OWNER', 'RECEPTION'] },
  { href: '/follow-ups', labelKey: 'followUps', roles: ['OWNER', 'RECEPTION'] },
  { href: '/messages', labelKey: 'messages', roles: ['OWNER', 'RECEPTION'] },
  { href: '/schedule', labelKey: 'schedule', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
  { href: '/leave', labelKey: 'leave', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
  { href: '/leave/inbox', labelKey: 'leaveInbox', roles: ['OWNER'] },
  { href: '/leave/calendar', labelKey: 'leaveCalendar', roles: ['OWNER', 'RECEPTION'] },
  { href: '/revenue', labelKey: 'revenue', roles: ['OWNER'] },
{ href: '/dashboard/daily', labelKey: 'dailySummary', roles: ['OWNER'] },
  { href: '/dashboard/services', labelKey: 'services', roles: ['OWNER'] },
  { href: '/users', labelKey: 'users', roles: ['OWNER'] },
  { href: '/staff', labelKey: 'staff', roles: ['OWNER'] },
  { href: '/settings', labelKey: 'settings', roles: ['OWNER'] },
  { href: '/audit', labelKey: 'audit', roles: ['OWNER'] },
] as const;

const DUTY_STATUS_KEYS: Record<PublicDoctorStatus, string> = {
  ON_DUTY_NOW: 'onDutyNow',
  ON_LEAVE: 'onLeave',
  NOT_ON_DUTY: 'notOnDuty',
};

function dutyClass(status: PublicDoctorStatus): string {
  if (status === 'ON_DUTY_NOW') return 'bg-emerald-50 text-emerald-700';
  if (status === 'ON_LEAVE') return 'bg-amber-50 text-amber-700';
  return 'bg-slate-100 text-slate-500';
}

type StatLink = {
  href: string;
  labelKey: string;
  value: string;
  risk?: boolean;
};

function StatCard({ stat, t }: { stat: StatLink; t: (key: string) => string }) {
  return (
    <Link href={stat.href} className="card p-4 hover:bg-slate-50">
      <div className={'text-2xl font-semibold ' + (stat.risk ? 'text-amber-700' : 'text-slate-900')}>
        {stat.value}
      </div>
      <div className="text-xs text-slate-500">{t(stat.labelKey)}</div>
    </Link>
  );
}

export default async function DashboardPage() {
  const user = await requirePageRole(['OWNER', 'RECEPTION', 'STAFF']);
  const t = await getTranslations('dashboard');
  const tc = await getTranslations('common');
  const tn = await getTranslations('nav');
  const th = await getTranslations('hr');

  const [settings, data] = await Promise.all([getSettingsMap(), getDashboardData(user)]);
  const tz = timezoneFrom(settings);
  const currency = settingsFromMap(settings).currency;
  const money = (value: string): string => formatMoney(value, currency);
  const todayKey = clinicDateKey(new Date(), tz);

  const roleLabel =
    user.role === 'OWNER'
      ? tc('roleOwner')
      : user.role === 'RECEPTION'
        ? tc('roleReception')
        : tc('roleStaff');

  const links = QUICK_LINKS.filter((link) => (link.roles as readonly Role[]).includes(user.role));

  let stats: StatLink[] = [];
  if (user.role === 'OWNER') {
    stats = [
      { href: '/dashboard/daily', labelKey: 'statRevenueToday', value: money(data.revenueToday) },
      { href: '/revenue', labelKey: 'statRevenueWeek', value: money(data.revenueWeek) },
      {
        href: `/appointments?view=day&day=${todayKey}`,
        labelKey: 'statTodayAppointments',
        value: String(data.todayAppointments),
      },
      { href: '/requests', labelKey: 'statNewRequests', value: String(data.newRequests) },
      { href: '/sales/voids', labelKey: 'statPendingVoids', value: String(data.pendingVoids) },
      { href: '/leave/inbox', labelKey: 'statPendingLeave', value: String(data.pendingLeave) },
      {
        href: '/messages',
        labelKey: 'statMessagesDue',
        value: String(data.messagesDueToday + data.messagesOverdue),
        risk: data.messagesDueToday + data.messagesOverdue > 0,
      },
      { href: '/follow-ups?status=overdue', labelKey: 'statOverdueFollowUps', value: String(data.overdueFollowUps) },
    ];
  } else if (user.role === 'RECEPTION') {
    stats = [
      { href: `/appointments?view=day&day=${todayKey}`, labelKey: 'statTodayAppointments', value: String(data.todayAppointments) },
      { href: '/requests', labelKey: 'statNewRequests', value: String(data.newRequests) },
      {
        href: '/messages',
        labelKey: 'statMessagesDue',
        value: String(data.messagesDueToday + data.messagesOverdue),
        risk: data.messagesDueToday + data.messagesOverdue > 0,
      },
      { href: '/follow-ups?status=overdue', labelKey: 'statOverdueFollowUps', value: String(data.overdueFollowUps) },
    ];
  } else if (data.staff) {
    stats = [
      { href: '/appointments', labelKey: 'statMyAppointmentsToday', value: String(data.staff.appointmentsToday) },
      { href: '/schedule', labelKey: 'statMyShiftsWeek', value: String(data.staff.shiftsThisWeek) },
      {
        href: '/leave',
        labelKey: 'statMyLeaveRemaining',
        value: data.staff.leaveRemaining === null ? '—' : String(data.staff.leaveRemaining),
      },
    ];
  }

  const doctors = data.doctors;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <p className="mt-1 text-sm text-slate-600">
          {t('subtitle', { name: user.name, role: roleLabel })}
        </p>
      </div>

      {user.role !== 'STAFF' && data.messagesDueToday + data.messagesOverdue > 0 ? (
        <Link
          href="/messages"
          className="block rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100"
        >
          {t('messagesBanner', { due: data.messagesDueToday, overdue: data.messagesOverdue })}
        </Link>
      ) : null}

      {stats.length ? (
        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {stats.map((stat) => (
            <StatCard key={stat.href} stat={stat} t={t as never} />
          ))}
        </section>
      ) : null}

      {doctors.length ? (
        <section className="space-y-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {th('onDutyTitle')}
          </h2>
          <div className="card divide-y divide-slate-100">
            {doctors.map((doctor) => (
              <div key={doctor.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                <span className="font-medium text-slate-900">{doctor.name}</span>
                {doctor.specialty ? (
                  <span className="text-slate-500">{doctor.specialty}</span>
                ) : null}
                <span className="ml-auto text-xs text-slate-500">
                  {doctor.todayShifts.length
                    ? doctor.todayShifts.map((shift) => `${shift.start}–${shift.end}`).join(', ')
                    : th('noShiftsToday')}
                </span>
                <span
                  className={
                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                    dutyClass(doctor.status)
                  }
                >
                  {th(DUTY_STATUS_KEYS[doctor.status] as never)}
                </span>
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {links.length ? (
        <section className="space-y-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {t('quickLinks')}
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {links.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="card p-4 text-sm font-medium text-slate-800 transition-colors hover:bg-slate-50"
              >
                {tn(link.labelKey as 'users')}
              </Link>
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}