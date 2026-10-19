import { getTranslations } from 'next-intl/server';
import Link from 'next/link';
import { requirePageRole } from '@/lib/require-role';
import { formatMoney } from '@/lib/money';
import { clinicDateKey, dateKeyAdd } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { getDailySummary, formatGeneratedAt } from '@/modules/dashboard';
import type { RevenueBucket } from '@/modules/sales';
import { SALES_OWNER_ROLES } from '@/modules/sales/roles';
import DailyEmailButton from '@/modules/dashboard/components/DailyEmailButton';
import PrintPageButton from '@/components/PrintPageButton';

type SearchParams = Promise<{ day?: string }>;

function BucketTable({
  t,
  buckets,
  money,
}: {
  t: (key: string) => string;
  buckets: RevenueBucket[];
  money: (value: string) => string;
}) {
  if (buckets.length === 0) return <p className="text-sm text-slate-500">{t('noSales')}</p>;
  return (
    <table className="w-full text-left text-sm">
      <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
        <tr>
          <th className="py-2 pr-3 font-medium">{t('label')}</th>
          <th className="py-2 pr-3 font-medium">{t('columnCount')}</th>
          <th className="py-2 font-medium">{t('columnTotal')}</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {buckets.map((bucket) => (
          <tr key={bucket.key}>
            <td className="py-2 pr-3 text-slate-800">{bucket.label}</td>
            <td className="py-2 pr-3 text-slate-600">{bucket.count}</td>
            <td className="py-2 font-medium text-slate-900">{money(bucket.total)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export default async function DailySummaryPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(SALES_OWNER_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('dashboard');

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const todayKey = clinicDateKey(new Date(), tz);
  const dayKey = sp.day && /^\d{4}-\d{2}-\d{2}$/.test(sp.day) ? sp.day : todayKey;
  const currency = typeof settings.currency === 'string' ? settings.currency : 'ETB';

  const summary = await getDailySummary(dayKey, tz);
  const money = (value: string): string => formatMoney(value, currency);
  const report = summary.report;

  return (
    <div id="print-daily" className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('dailyTitle')}</h1>
          <p className="mt-1 text-sm text-slate-600">
            {t('dailySubtitle', { day: dayKey })}
            <span className="ml-2 text-xs text-slate-400">
              {t('generatedAt', { time: formatGeneratedAt(new Date(), tz) })}
            </span>
          </p>
        </div>
        <div className="no-print flex flex-wrap items-center gap-2">
          <Link href={`/dashboard/daily?day=${dateKeyAdd(dayKey, -1)}`} className="btn-secondary">
            {t('previousDay')}
          </Link>
          <Link href={`/dashboard/daily?day=${dateKeyAdd(dayKey, 1)}`} className="btn-secondary">
            {t('nextDay')}
          </Link>
          <PrintPageButton />
          <DailyEmailButton />
        </div>
      </div>

      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div className="card p-4">
          <div className="text-xs text-slate-500">{t('dailyTotalRevenue')}</div>
          <div className="text-2xl font-semibold text-slate-900">{money(report.totalRevenue)}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs text-slate-500">{t('dailySalesCount')}</div>
          <div className="text-2xl font-semibold text-slate-900">{report.salesCount}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs text-slate-500">{t('dailyAverageSale')}</div>
          <div className="text-2xl font-semibold text-slate-900">{money(report.averageSale)}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs text-slate-500">{t('dailyVoided')}</div>
          <div className="text-2xl font-semibold text-slate-900">
            {money(report.voidedTotal)} <span className="text-sm text-slate-400">({report.voidedCount})</span>
          </div>
        </div>
        <div className="card p-4">
          <div className="text-xs text-slate-500">{t('dailyReceiptRange')}</div>
          <div className="text-sm font-semibold text-slate-900">
            {summary.receipts.length
              ? `${summary.receipts[0]} – ${summary.receipts[summary.receipts.length - 1]}`
              : '—'}
          </div>
          <div className="text-xs text-slate-400">{t('dailyReceiptsCount', { count: summary.receipts.length })}</div>
        </div>
      </section>

      <div className="grid gap-5 md:grid-cols-2">
        <section className="card space-y-3 p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {t('byPayment')}
          </h2>
          <BucketTable t={t as never} buckets={report.byPaymentMethod} money={money} />
        </section>
        <section className="card space-y-3 p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {t('byDoctor')}
          </h2>
          <BucketTable t={t as never} buckets={report.byDoctor} money={money} />
        </section>
        <section className="card space-y-3 p-5 md:col-span-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {t('byService')}
          </h2>
          <BucketTable t={t as never} buckets={report.byService} money={money} />
        </section>
        <section className="card space-y-3 p-5 md:col-span-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
            {t('dayContext')}
          </h2>
          <dl className="grid grid-cols-2 gap-3 text-sm lg:grid-cols-4">
            <div>
              <dt className="text-xs text-slate-500">{t('appointmentsToday')}</dt>
              <dd className="text-lg font-semibold text-slate-900">{summary.appointmentsToday}</dd>
            </div>
          </dl>
        </section>
      </div>
    </div>
  );
}