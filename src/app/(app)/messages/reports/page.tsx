import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { MESSAGE_OWNER_ROLES } from '@/modules/messaging';
import { TEMPLATE_LABEL_KEYS } from '@/modules/messaging/constants';
import { expiredReport } from '@/modules/messaging/queries';

export default async function MessageReportsPage() {
  await requirePageRole(MESSAGE_OWNER_ROLES);
  const t = await getTranslations('messages');
  const days = await expiredReport();

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('reportsTitle')}</h1>
          <p className="mt-1 text-sm text-slate-600">{t('reportsSubtitle')}</p>
        </div>
        <Link href="/messages" className="btn-secondary">
          {t('backToOutbox')}
        </Link>
      </div>

      {days.length === 0 ? (
        <p className="card p-5 text-sm text-slate-600">{t('noExpired')}</p>
      ) : (
        <div className="space-y-4">
          {days.map((day) => (
            <section key={day.dayKey} className="card">
              <h2 className="border-b border-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">
                {day.dayKey} · {t('notSentCount', { count: day.count })}
              </h2>
              <ul className="divide-y divide-slate-100">
                {day.tasks.map((task) => (
                  <li key={task.id} className="px-4 py-2 text-sm">
                    <span className="font-medium text-slate-800">{task.patientName}</span>{' '}
                    <span className="text-xs text-slate-500">{task.patientPhoneE164}</span>{' '}
                    <span className="text-xs text-slate-400">
                      {t(TEMPLATE_LABEL_KEYS[task.templateKey] as never)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
