import { prisma } from '@/lib/prisma';
import { formatMoney } from '@/lib/money';
import { dateKeyAdd, combineDateAndTime, formatInTz } from '@/lib/datetime';
import { listRevenueSales, buildRevenueReport, type RevenueReport } from '@/modules/sales';
import { countAppointmentsOnDay } from '@/modules/appointments';

export type DailySummary = {
  dayKey: string;
  report: RevenueReport;
  /** Confirmed receipts issued that day (in receipt-number order). */
  receipts: string[];
  appointmentsToday: number;
};

/**
 * One clinic day's sales picture: confirmed revenue (with by-service/doctor/
 * payment breakdowns), voids, and the issued receipt-number range — the page
 * and the end-of-day email both render from this one query set.
 */
export async function getDailySummary(
  dayKey: string,
  timeZone: string,
): Promise<DailySummary> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dayKey)) throw new Error('invalid day key');
  const from = combineDateAndTime(dayKey, '00:00', timeZone);
  const to = combineDateAndTime(dateKeyAdd(dayKey, 1), '00:00', timeZone);

  const [sales, receipts, appointmentsToday] = await Promise.all([
    listRevenueSales(from, to),
    prisma.sale.findMany({
      where: { status: 'CONFIRMED', saleDate: { gte: from, lt: to } },
      select: { receiptNumber: true },
      orderBy: { receiptNumber: 'asc' },
    }),
    countAppointmentsOnDay(dayKey),
  ]);

  return {
    dayKey,
    report: buildRevenueReport(sales, timeZone),
    receipts: receipts.map((row) => row.receiptNumber),
    appointmentsToday,
  };
}

/** Human-readable plain-text body for the end-of-day email. */
export function buildDailySummaryText(
  summary: DailySummary,
  opts: { clinicName: string; currency: string; sentAt: Date },
): string {
  const money = (value: string): string => formatMoney(value, opts.currency);
  const lines: string[] = [
    `${opts.clinicName} — Daily sales summary`,
    `Date: ${summary.dayKey}`,
    `Generated: ${opts.sentAt.toISOString()}`,
    '',
    `Appointments today (scheduled/confirmed): ${summary.appointmentsToday}`,
    `Confirmed sales: ${summary.report.salesCount}`,
    `Total revenue: ${money(summary.report.totalRevenue)}`,
    `Average sale: ${money(summary.report.averageSale)}`,
    `Voided: ${summary.report.voidedCount} (${money(summary.report.voidedTotal)})`,
    summary.receipts.length
      ? `Receipt range: ${summary.receipts[0]} … ${summary.receipts[summary.receipts.length - 1]}`
      : 'Receipt range: none',
    '',
    'Revenue by service:',
    ...summary.report.byService.map(
      (bucket) => `  ${bucket.label}: ${money(bucket.total)} (${bucket.count})`,
    ),
    ...(summary.report.byService.length ? [] : ['  none']),
    '',
    'Revenue by doctor:',
    ...summary.report.byDoctor.map(
      (bucket) => `  ${bucket.label}: ${money(bucket.total)} (${bucket.count})`,
    ),
    ...(summary.report.byDoctor.length ? [] : ['  none']),
    '',
    'Revenue by payment method:',
    ...summary.report.byPaymentMethod.map(
      (bucket) => `  ${bucket.label}: ${money(bucket.total)} (${bucket.count})`,
    ),
    ...(summary.report.byPaymentMethod.length ? [] : ['  none']),
  ];
  return lines.join('\n');
}

/** Clinic-local timestamp for the "generated" line. */
export function formatGeneratedAt(now: Date, timeZone: string): string {
  return formatInTz(now, timeZone);
}

export const DAILY_SUMMARY_RECEIT_MAX = 5000;