import { describe, expect, it } from 'vitest';
import {
  countCalendarDays,
  findOverlappingLeave,
  isOnLeaveAt,
  leaveBalance,
  leaveCoversDate,
  leaveRangesOverlap,
  requestedDays,
  type LeaveLike,
} from './leave';

function leave(partial: Partial<LeaveLike> & Pick<LeaveLike, 'id'>): LeaveLike {
  return {
    staffId: 'p1',
    type: 'ANNUAL',
    status: 'APPROVED',
    startDate: '2026-10-08',
    endDate: '2026-10-08',
    halfDay: false,
    ...partial,
  };
}

describe('leave geometry', () => {
  it('counts inclusive calendar days', () => {
    expect(countCalendarDays('2026-10-08', '2026-10-08')).toBe(1);
    expect(countCalendarDays('2026-10-08', '2026-10-10')).toBe(3);
  });

  it('half days count 0.5', () => {
    expect(requestedDays({ startDate: '2026-10-08', endDate: '2026-10-08', halfDay: true })).toBe(
      0.5,
    );
    expect(requestedDays({ startDate: '2026-10-08', endDate: '2026-10-08', halfDay: false })).toBe(
      1,
    );
  });

  it('detects overlapping ranges (inclusive)', () => {
    expect(leaveRangesOverlap('2026-10-08', '2026-10-10', '2026-10-10', '2026-10-12')).toBe(true);
    expect(leaveRangesOverlap('2026-10-08', '2026-10-10', '2026-10-11', '2026-10-12')).toBe(false);
  });

  it('rejects a request overlapping an existing approved leave for the same person', () => {
    const existing = [leave({ id: 'l1', startDate: '2026-10-08', endDate: '2026-10-12' })];
    expect(
      findOverlappingLeave(existing, {
        staffId: 'p1',
        startDate: '2026-10-11',
        endDate: '2026-10-15',
      })?.id,
    ).toBe('l1');
    expect(
      findOverlappingLeave(existing, {
        staffId: 'p2',
        startDate: '2026-10-11',
        endDate: '2026-10-15',
      }),
    ).toBeUndefined();
  });

  it('only APPROVED leave covers a date (suppresses duty)', () => {
    const approved = leave({
      id: 'l1',
      status: 'APPROVED',
      startDate: '2026-10-08',
      endDate: '2026-10-10',
    });
    const pending = leave({
      id: 'l2',
      status: 'REQUESTED',
      startDate: '2026-10-08',
      endDate: '2026-10-08',
    });
    expect(leaveCoversDate(approved, '2026-10-09')).toBe(true);
    expect(leaveCoversDate(approved, '2026-10-11')).toBe(false);
    expect(leaveCoversDate(pending, '2026-10-08')).toBe(false);
    expect(isOnLeaveAt([approved], 'p1', '2026-10-09')).toBe(true);
    expect(isOnLeaveAt([approved], 'p2', '2026-10-09')).toBe(false);
  });

  it('computes annual balance for the year (half days included)', () => {
    const leaves = [
      leave({ id: 'a', startDate: '2026-03-02', endDate: '2026-03-04' }), // 3 days
      leave({ id: 'b', startDate: '2026-06-01', endDate: '2026-06-01', halfDay: true }), // 0.5
      leave({ id: 'c', startDate: '2026-07-01', endDate: '2026-07-02', type: 'SICK' }), // ignored
      leave({ id: 'd', startDate: '2025-12-30', endDate: '2025-12-31' }), // other year
      leave({ id: 'e', startDate: '2026-08-01', endDate: '2026-08-02', status: 'REQUESTED' }), // ignored
    ];
    const balance = leaveBalance(20, leaves, 2026);
    expect(balance.taken).toBe(3.5);
    expect(balance.remaining).toBe(16.5);
  });

  it('returns no balance when entitlement is unset', () => {
    const balance = leaveBalance(null, [], 2026);
    expect(balance.entitlement).toBeNull();
    expect(balance.remaining).toBeNull();
    expect(balance.taken).toBe(0);
  });
});
