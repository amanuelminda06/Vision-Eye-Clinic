import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatMoney } from '@/lib/money';
import { clinicDateKey, endOfDay, startOfDay } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { CATEGORY_LABEL_KEYS, isServiceCategory } from '@/modules/services';
import {
  PAYMENT_LABEL_KEYS,
  SALES_OWNER_ROLES,
  buildRevenueReport,
  fillDailyTrend,
  inRange,
  isPeriodPreset,
  listRevenueSales,
  percentChange,
  resolvePeriod,
  type RevenueBucket,
  type PeriodPreset,
} from '@/modules/sales';

type SearchParams = Promise<{ period?: string; from?: string; to?: string }>;

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const PRESET_KEYS: Record<PeriodPreset, string> = {
  today: 'periodToday',
  week: 'periodWeek',
  month: 'periodMonth',
  lastMonth: 'periodLastMonth',
  custom: 'periodCustom',
};

function presetLink(preset: PeriodPreset, from = '', to = ''): string {
  const params = new URLSearchParams({ period: preset });
  if (preset === 'custom') {
    if (from) params.set('from', from);
    if (to) params.set('to', to);
  }
  return `/revenue?${params.toString()}`;
}

export default async function RevenuePage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(SALES_OWNER_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('sales');
  const tc = await getTranslations('common');

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const now = new Date();

  const preset: PeriodPreset = isPeriodPreset(sp.period) ? sp.period : 'today';
  const from = sp.from && DATE_RE.test(sp.from) ? sp.from : '';
  const to = sp.to && DATE_RE.test(sp.to) ? sp.to : '';
  const { current, previous } = resolvePeriod(preset, now, tz, { from, to });

  const sales = await listRevenueSales(
    startOfDay(previous.fromKey, tz),
    endOfDay(current.toKey, tz),
  );
  const currentSales = sales.filter((sale) => inRange(clinicDateKey(sale.saleDate, tz), current));
  const previousSales = sales.filter((sale) => inRange(clinicDateKey(sale.saleDate, tz), previous));

  const report = buildRevenueReport(currentSales, tz);
  const previousReport = buildRevenueReport(previousSales, tz);
  const change = percentChange(report.totalRevenue, previousReport.totalRevenue);
  const trend = fillDailyTrend(report.byDay, current.fromKey, current.toKey);
  const trendMax = Math.max(1, ...trend.map((day) => Number(day.total)));

  const bucketLabel = (
    bucket: RevenueBucket,
    kind: 'service' | 'category' | 'doctor' | 'payment',
  ): string => {
    if (kind === 'category' && isServiceCategory(bucket.key)) {
      return t(CATEGORY_LABEL_KEYS[bucket.key] as never);
    }
    if (kind === 'payment' && bucket.key in PAYMENT_LABEL_KEYS) {
      return t(PAYMENT_LABEL_KEYS[bucket.key as keyof typeof PAYMENT_LABEL_KEYS] as never);
    }
    return bucket.label || '—';
  };

  const breakdown = (
    title: string,
    buckets: RevenueBucket[],
    kind: 'service' | 'category' | 'doctor' | 'payment',
  ) => (
    <div className="card space-y-3 p-5">
      <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
      {buckets.length === 0 ? (
        <p className="text-sm text-slate-500">{tc('empty')}</p>
      ) : (
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <th className="py-2 pr-3 font-medium">{t('name')}</th>
              <th className="py-2 pr-3 text-right font-medium">{t('salesCount')}</th>
              <th className="py-2 text-right font-medium">{t('total')}</th>
            </tr>
          </thead>
          <tbody>
            {buckets.map((bucket) => (
              <tr key={bucket.key} className="border-b border-slate-100 last:border-0">
                <td className="py-2 pr-3 text-slate-700">{bucketLabel(bucket, kind)}</td>
                <td className="py-2 pr-3 text-right text-slate-600">{bucket.count}</td>
                <td className="py-2 text-right font-medium text-slate-900">
                  {formatMoney(bucket.total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-semibold text-slate-900">{t('revenueTitle')}</h1>

      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-wrap gap-2">
          {(Object.keys(PRESET_KEYS) as PeriodPreset[]).map((value) => (
            <Link
              key={value}
              href={presetLink(value, from, to)}
              prefetch={false}
              className={value === preset ? 'btn-primary' : 'btn-secondary'}
            >
              {t(PRESET_KEYS[value] as never)}
            </Link>
          ))}
        </div>
        {preset === 'custom' ? (
          <form method="get" className="flex flex-wrap items-end gap-2">
            <input type="hidden" name="period" value="custom" />
            <div>
              <label className="label" htmlFor="from">
                {t('from')}
              </label>
              <input
                className="input-field"
                id="from"
                name="from"
                type="date"
                defaultValue={current.fromKey}
              />
            </div>
            <div>
              <label className="label" htmlFor="to">
                {t('to')}
              </label>
              <input
                className="input-field"
                id="to"
                name="to"
                type="date"
                defaultValue={current.toKey}
              />
            </div>
            <button type="submit" className="btn-secondary">
              {tc('filter')}
            </button>
          </form>
        ) : null}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="card space-y-1 p-5">
          <p className="text-xs uppercase text-slate-500">{t('totalRevenue')}</p>
          <p className="text-2xl font-semibold text-slate-900">
            {formatMoney(report.totalRevenue)}
          </p>
          <p className="text-xs text-slate-500">
            {t('vsPrevious', {
              change: change === null ? '—' : `${change >= 0 ? '+' : ''}${change}%`,
            })}
          </p>
        </div>
        <div className="card space-y-1 p-5">
          <p className="text-xs uppercase text-slate-500">{t('salesCount')}</p>
          <p className="text-2xl font-semibold text-slate-900">{report.salesCount}</p>
        </div>
        <div className="card space-y-1 p-5">
          <p className="text-xs uppercase text-slate-500">{t('averageSale')}</p>
          <p className="text-2xl font-semibold text-slate-900">{formatMoney(report.averageSale)}</p>
        </div>
        <div className="card space-y-1 p-5">
          <p className="text-xs uppercase text-slate-500">{t('voidedTotal')}</p>
          <p className="text-2xl font-semibold text-red-700">{formatMoney(report.voidedTotal)}</p>
          <p className="text-xs text-slate-500">
            {t('voidedCount', { count: report.voidedCount })}
          </p>
        </div>
      </div>

      <div className="card space-y-3 p-5">
        <h2 className="text-sm font-semibold text-slate-800">{t('dailyTrend')}</h2>
        {trend.length === 0 ? (
          <p className="text-sm text-slate-500">{tc('empty')}</p>
        ) : (
          <ul className="space-y-1">
            {trend.map((day) => (
              <li key={day.dateKey} className="flex items-center gap-3 text-xs">
                <span className="w-24 shrink-0 text-slate-500">{day.dateKey}</span>
                <span
                  className="h-2 rounded bg-slate-800"
                  style={{
                    width: `${(Number(day.total) / trendMax) * 100}%`,
                    minWidth: day.count > 0 ? '2px' : '0',
                  }}
                />
                <span className="text-slate-600">
                  {formatMoney(day.total)} ({day.count})
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        {breakdown(t('byService'), report.byService, 'service')}
        {breakdown(t('byCategory'), report.byCategory, 'category')}
        {breakdown(t('byDoctor'), report.byDoctor, 'doctor')}
        {breakdown(t('byPaymentMethod'), report.byPaymentMethod, 'payment')}
      </div>
    </div>
  );
}
