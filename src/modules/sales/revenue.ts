import Decimal from 'decimal.js';
import { addMoney, averageMoney, percentChange, toDecimalString } from '@/lib/money';
import { clinicDateKey, dateKeyAdd } from '@/lib/datetime';
import type { PaymentMethodValue, SaleStatusValue } from './constants';

/**
 * Revenue aggregation (pure — unit tested). Only CONFIRMED sales count as
 * revenue; VOIDED totals are reported separately and DRAFT is ignored.
 * Day buckets use the clinic timezone, never the server's local time.
 */

export type RevenueSaleInput = {
  id: string;
  saleDate: Date;
  status: SaleStatusValue;
  total: string;
  paymentMethod: PaymentMethodValue;
  doctorName: string | null;
  lines: Array<{ serviceName: string; category: string; qty: number; lineTotal: string }>;
};

export type RevenueBucket = { key: string; label: string; total: string; count: number };

export type RevenueReport = {
  totalRevenue: string;
  salesCount: number;
  averageSale: string;
  voidedTotal: string;
  voidedCount: number;
  byService: RevenueBucket[];
  byCategory: RevenueBucket[];
  byDoctor: RevenueBucket[];
  byPaymentMethod: RevenueBucket[];
  byDay: Array<{ dateKey: string; total: string; count: number }>;
};

type Accumulator = { total: Decimal; count: number; label: string };

function bump(map: Map<string, Accumulator>, key: string, label: string, amount: string): void {
  const current = map.get(key);
  if (current) {
    current.total = current.total.plus(amount);
    current.count += 1;
  } else {
    map.set(key, { total: new Decimal(amount), count: 1, label });
  }
}

function toBuckets(map: Map<string, Accumulator>): RevenueBucket[] {
  return [...map]
    .map(([key, value]) => ({
      key,
      label: value.label,
      total: toDecimalString(value.total.toDecimalPlaces(2)),
      count: value.count,
    }))
    .sort(
      (a, b) => new Decimal(b.total).comparedTo(new Decimal(a.total)) || a.key.localeCompare(b.key),
    );
}

export function buildRevenueReport(sales: RevenueSaleInput[], timeZone: string): RevenueReport {
  const confirmed = sales.filter((sale) => sale.status === 'CONFIRMED');
  const voided = sales.filter((sale) => sale.status === 'VOIDED');

  const byService = new Map<string, Accumulator>();
  const byCategory = new Map<string, Accumulator>();
  const byDoctor = new Map<string, Accumulator>();
  const byPaymentMethod = new Map<string, Accumulator>();
  const byDay = new Map<string, Accumulator>();

  for (const sale of confirmed) {
    const dayKey = clinicDateKey(sale.saleDate, timeZone);
    const day = byDay.get(dayKey);
    if (day) {
      day.total = day.total.plus(sale.total);
      day.count += 1;
    } else {
      byDay.set(dayKey, { total: new Decimal(sale.total), count: 1, label: dayKey });
    }

    const doctorKey = sale.doctorName ?? '';
    bump(byDoctor, doctorKey, sale.doctorName ?? '—', sale.total);
    bump(byPaymentMethod, sale.paymentMethod, sale.paymentMethod, sale.total);

    for (const line of sale.lines) {
      bump(byService, line.serviceName, line.serviceName, line.lineTotal);
      bump(byCategory, line.category, line.category, line.lineTotal);
    }
  }

  const totalRevenue = addMoney(...confirmed.map((sale) => sale.total));
  const voidedTotal = addMoney(...voided.map((sale) => sale.total));

  return {
    totalRevenue: toDecimalString(totalRevenue),
    salesCount: confirmed.length,
    averageSale: toDecimalString(averageMoney(totalRevenue, confirmed.length)),
    voidedTotal: toDecimalString(voidedTotal),
    voidedCount: voided.length,
    byService: toBuckets(byService),
    byCategory: toBuckets(byCategory),
    byDoctor: toBuckets(byDoctor),
    byPaymentMethod: toBuckets(byPaymentMethod),
    byDay: [...byDay]
      .map(([dateKey, value]) => ({
        dateKey,
        total: toDecimalString(value.total.toDecimalPlaces(2)),
        count: value.count,
      }))
      .sort((a, b) => a.dateKey.localeCompare(b.dateKey)),
  };
}

/** Continuous day list (zero-filled) for the trend chart. */
export function fillDailyTrend(
  days: RevenueReport['byDay'],
  fromKey: string,
  toKey: string,
): RevenueReport['byDay'] {
  const byKey = new Map(days.map((day) => [day.dateKey, day]));
  const filled: RevenueReport['byDay'] = [];
  let cursor = fromKey;
  for (let i = 0; i <= 400; i++) {
    const existing = byKey.get(cursor);
    filled.push(existing ?? { dateKey: cursor, total: '0.00', count: 0 });
    if (cursor === toKey) break;
    cursor = dateKeyAdd(cursor, 1);
  }
  return filled;
}

export { percentChange };

// ---------------------------------------------------------------------------
// Period presets
// ---------------------------------------------------------------------------

export const PERIOD_PRESETS = ['today', 'week', 'month', 'lastMonth', 'custom'] as const;
export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

export type DateRange = { fromKey: string; toKey: string };

export function isPeriodPreset(value: unknown): value is PeriodPreset {
  return typeof value === 'string' && (PERIOD_PRESETS as readonly string[]).includes(value);
}

function dayCount(fromKey: string, toKey: string): number {
  const from = Date.parse(`${fromKey}T00:00:00Z`);
  const to = Date.parse(`${toKey}T00:00:00Z`);
  return Math.round((to - from) / 86_400_000) + 1;
}

function previousEquivalent(range: DateRange): DateRange {
  const length = dayCount(range.fromKey, range.toKey);
  const toKey = dateKeyAdd(range.fromKey, -1);
  return { fromKey: dateKeyAdd(toKey, -(length - 1)), toKey };
}

function monthRange(year: number, month: number): DateRange {
  const pad = (n: number) => String(n).padStart(2, '0');
  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
  return {
    fromKey: `${year}-${pad(month)}-01`,
    toKey: `${year}-${pad(month)}-${pad(lastDay)}`,
  };
}

/**
 * Resolves a preset into the current period and the previous equivalent one
 * (used for the % comparison). All boundaries are clinic-timezone calendar
 * days.
 */
export function resolvePeriod(
  preset: PeriodPreset,
  now: Date,
  timeZone: string,
  custom: { from?: string; to?: string } = {},
): { current: DateRange; previous: DateRange } {
  const today = clinicDateKey(now, timeZone);
  const [year, month] = today.split('-').map(Number);

  let current: DateRange;
  switch (preset) {
    case 'week': {
      const weekday = new Date(`${today}T00:00:00Z`).getUTCDay(); // 0 = Sunday
      const sinceMonday = (weekday + 6) % 7;
      current = { fromKey: dateKeyAdd(today, -sinceMonday), toKey: today };
      break;
    }
    case 'month':
      current = { fromKey: `${year}-${String(month).padStart(2, '0')}-01`, toKey: today };
      break;
    case 'lastMonth': {
      const prevMonth = month === 1 ? 12 : month - 1;
      const prevYear = month === 1 ? year - 1 : year;
      current = monthRange(prevYear, prevMonth);
      const beforeMonth = prevMonth === 1 ? 12 : prevMonth - 1;
      const beforeYear = prevMonth === 1 ? prevYear - 1 : prevYear;
      return { current, previous: monthRange(beforeYear, beforeMonth) };
    }
    case 'custom': {
      const from = custom.from && /^\d{4}-\d{2}-\d{2}$/.test(custom.from) ? custom.from : today;
      const to = custom.to && /^\d{4}-\d{2}-\d{2}$/.test(custom.to) ? custom.to : today;
      current = from <= to ? { fromKey: from, toKey: to } : { fromKey: to, toKey: from };
      break;
    }
    case 'today':
    default:
      current = { fromKey: today, toKey: today };
  }

  return { current, previous: previousEquivalent(current) };
}

/** True when a clinic-tz date key falls inside a range (inclusive). */
export function inRange(dateKey: string, range: DateRange): boolean {
  return dateKey >= range.fromKey && dateKey <= range.toKey;
}
