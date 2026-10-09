import { describe, expect, it } from 'vitest';
import {
  buildRevenueReport,
  fillDailyTrend,
  inRange,
  percentChange,
  resolvePeriod,
  type RevenueSaleInput,
} from './revenue';

const TZ = 'Africa/Addis_Ababa';

function sale(
  overrides: Partial<RevenueSaleInput> & Pick<RevenueSaleInput, 'id' | 'total' | 'status'>,
): RevenueSaleInput {
  return {
    saleDate: new Date('2026-10-08T09:00:00.000Z'),
    paymentMethod: 'CASH',
    doctorName: null,
    lines: [
      {
        serviceName: 'Eye Consultation',
        category: 'consultation',
        qty: 1,
        lineTotal: overrides.total,
      },
    ],
    ...overrides,
  };
}

describe('buildRevenueReport', () => {
  it('counts only CONFIRMED as revenue and reports VOIDED separately', () => {
    const report = buildRevenueReport(
      [
        sale({ id: '1', total: '300.00', status: 'CONFIRMED' }),
        sale({ id: '2', total: '500.00', status: 'CONFIRMED' }),
        sale({ id: '3', total: '900.00', status: 'VOIDED' }),
        sale({ id: '4', total: '1500.00', status: 'DRAFT' }),
      ],
      TZ,
    );

    expect(report.totalRevenue).toBe('800.00');
    expect(report.salesCount).toBe(2);
    expect(report.averageSale).toBe('400.00');
    expect(report.voidedTotal).toBe('900.00');
    expect(report.voidedCount).toBe(1);
  });

  it('breaks revenue down by service, category, doctor and payment method', () => {
    const report = buildRevenueReport(
      [
        sale({
          id: '1',
          total: '1000.00',
          status: 'CONFIRMED',
          paymentMethod: 'CARD',
          doctorName: 'Dr. Abebe',
          lines: [
            { serviceName: 'OCT Scan', category: 'diagnostics', qty: 1, lineTotal: '1000.00' },
          ],
        }),
        sale({
          id: '2',
          total: '300.00',
          status: 'CONFIRMED',
          paymentMethod: 'CARD',
          doctorName: 'Dr. Abebe',
          lines: [
            {
              serviceName: 'Eye Consultation',
              category: 'consultation',
              qty: 1,
              lineTotal: '300.00',
            },
          ],
        }),
      ],
      TZ,
    );

    expect(report.byService.map((bucket) => bucket.key)).toEqual(['OCT Scan', 'Eye Consultation']);
    expect(report.byService[0].total).toBe('1000.00');
    expect(report.byCategory.find((bucket) => bucket.key === 'consultation')?.total).toBe('300.00');
    expect(report.byDoctor[0]).toMatchObject({ key: 'Dr. Abebe', total: '1300.00', count: 2 });
    expect(report.byPaymentMethod[0]).toMatchObject({ key: 'CARD', total: '1300.00' });
  });

  it('buckets days in the clinic timezone (23:30 Addis stays on the same day)', () => {
    const report = buildRevenueReport(
      [
        sale({
          id: '1',
          total: '100.00',
          status: 'CONFIRMED',
          saleDate: new Date('2026-10-08T20:30:00.000Z'),
        }),
        sale({
          id: '2',
          total: '200.00',
          status: 'CONFIRMED',
          saleDate: new Date('2026-10-08T21:30:00.000Z'),
        }),
      ],
      TZ,
    );

    const days = Object.fromEntries(report.byDay.map((day) => [day.dateKey, day.total]));
    expect(days['2026-10-08']).toBe('100.00');
    expect(days['2026-10-09']).toBe('200.00');
  });
});

describe('fillDailyTrend', () => {
  it('zero-fills missing days', () => {
    const filled = fillDailyTrend(
      [{ dateKey: '2026-10-02', total: '50.00', count: 1 }],
      '2026-10-01',
      '2026-10-03',
    );
    expect(filled).toEqual([
      { dateKey: '2026-10-01', total: '0.00', count: 0 },
      { dateKey: '2026-10-02', total: '50.00', count: 1 },
      { dateKey: '2026-10-03', total: '0.00', count: 0 },
    ]);
  });
});

describe('resolvePeriod', () => {
  const now = new Date('2026-10-08T12:00:00.000Z'); // Thursday
  const nowTime = new Date('2026-10-08T12:00:00.000Z');

  it('resolves today with yesterday as the previous period', () => {
    const { current, previous } = resolvePeriod('today', now, TZ);
    expect(current).toEqual({ fromKey: '2026-10-08', toKey: '2026-10-08' });
    expect(previous).toEqual({ fromKey: '2026-10-07', toKey: '2026-10-07' });
  });

  it('resolves the current week from Monday', () => {
    const { current, previous } = resolvePeriod('week', nowTime, TZ);
    expect(current).toEqual({ fromKey: '2026-10-05', toKey: '2026-10-08' });
    expect(previous).toEqual({ fromKey: '2026-10-01', toKey: '2026-10-04' });
  });

  it('resolves the month and the previous equivalent span', () => {
    const { current, previous } = resolvePeriod('month', nowTime, TZ);
    expect(current).toEqual({ fromKey: '2026-10-01', toKey: '2026-10-08' });
    expect(previous).toEqual({ fromKey: '2026-09-23', toKey: '2026-09-30' });
  });

  it('resolves last month, handling the January boundary', () => {
    const lastMonth = resolvePeriod('lastMonth', nowTime, TZ);
    expect(lastMonth.current).toEqual({ fromKey: '2026-09-01', toKey: '2026-09-30' });
    expect(lastMonth.previous).toEqual({ fromKey: '2026-08-01', toKey: '2026-08-31' });

    const january = resolvePeriod('lastMonth', new Date('2026-01-15T12:00:00.000Z'), TZ);
    expect(january.current).toEqual({ fromKey: '2025-12-01', toKey: '2025-12-31' });
    expect(january.previous).toEqual({ fromKey: '2025-11-01', toKey: '2025-11-30' });
  });

  it('falls back to today for invalid custom ranges', () => {
    const { current } = resolvePeriod('custom', nowTime, TZ, { from: 'nope', to: '' });
    expect(current).toEqual({ fromKey: '2026-10-08', toKey: '2026-10-08' });
  });

  it('swaps reversed custom ranges', () => {
    const { current } = resolvePeriod('custom', nowTime, TZ, {
      from: '2026-10-05',
      to: '2026-10-01',
    });
    expect(current).toEqual({ fromKey: '2026-10-01', toKey: '2026-10-05' });
  });
});

describe('inRange / percentChange', () => {
  it('checks inclusive ranges', () => {
    expect(inRange('2026-10-03', { fromKey: '2026-10-01', toKey: '2026-10-05' })).toBe(true);
    expect(inRange('2026-09-30', { fromKey: '2026-10-01', toKey: '2026-10-05' })).toBe(false);
  });

  it('re-exports percentChange', () => {
    expect(percentChange('110.00', '100.00')).toBe(10);
  });
});
