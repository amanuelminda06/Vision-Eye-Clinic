import { clinicDateKey, dateKeyAdd, type DateInput } from '@/lib/datetime';
import { dateColumnToKey } from './shifts';

/**
 * Leave geometry (pure — unit tested). Leave is stored as inclusive calendar
 * dates (clinic local, `@db.Date`), so overlap and balances compare date keys.
 */

export type LeaveLike = {
  id: string;
  staffId: string;
  type: string;
  status: string;
  startDate: Date | string;
  endDate: Date | string;
  halfDay: boolean;
};

/** Inclusive day span between two `YYYY-MM-DD` keys (always >= 1). */
export function countCalendarDays(startKey: string, endKey: string): number {
  const from = Date.parse(`${startKey}T00:00:00Z`);
  const to = Date.parse(`${endKey}T00:00:00Z`);
  if (!Number.isFinite(from) || !Number.isFinite(to) || to < from) return 1;
  return Math.round((to - from) / 86_400_000) + 1;
}

/** Requested days for a request: inclusive span, minus 0.5 for a half day. */
export function requestedDays(
  request: Pick<LeaveLike, 'startDate' | 'endDate' | 'halfDay'>,
): number {
  const days = countCalendarDays(
    dateColumnToKey(request.startDate),
    dateColumnToKey(request.endDate),
  );
  return request.halfDay ? Math.max(0.5, days - 0.5) : days;
}

/** True when two inclusive date ranges intersect. */
export function leaveRangesOverlap(
  aStart: string,
  aEnd: string,
  bStart: string,
  bEnd: string,
): boolean {
  return aStart <= bEnd && bStart <= aEnd;
}

/** True when an already existing request overlaps `[startKey, endKey]`. */
export function findOverlappingLeave<T extends LeaveLike>(
  existing: T[],
  candidate: {
    staffId: string;
    startDate: Date | string;
    endDate: Date | string;
    ignoreId?: string;
  },
): T | undefined {
  const startKey = dateColumnToKey(candidate.startDate);
  const endKey = dateColumnToKey(candidate.endDate);
  return existing.find(
    (leave) =>
      leave.id !== candidate.ignoreId &&
      leave.staffId === candidate.staffId &&
      leaveRangesOverlap(
        startKey,
        endKey,
        dateColumnToKey(leave.startDate),
        dateColumnToKey(leave.endDate),
      ),
  );
}

/** True when an approved leave covers `dateKey` (inclusive). */
export function leaveCoversDate(
  leave: Pick<LeaveLike, 'status' | 'startDate' | 'endDate'>,
  dateKey: string,
): boolean {
  if (leave.status !== 'APPROVED') return false;
  const startKey = dateColumnToKey(leave.startDate);
  const endKey = dateColumnToKey(leave.endDate);
  return dateKey >= startKey && dateKey <= endKey;
}

/** Staff member is on approved leave at `dateKey`. */
export function isOnLeaveAt(leaves: LeaveLike[], staffId: string, dateKey: string): boolean {
  return leaves.some((leave) => leave.staffId === staffId && leaveCoversDate(leave, dateKey));
}

export type LeaveBalance = {
  entitlement: number | null;
  taken: number;
  remaining: number | null;
};

/**
 * Annual-leave balance for a calendar year. `taken` counts APPROVED ANNUAL
 * requests that start inside the year (half days count 0.5).
 */
export function leaveBalance(
  annualLeaveDays: number | null,
  leaves: LeaveLike[],
  year: number,
): LeaveBalance {
  const taken = leaves
    .filter((leave) => {
      if (leave.type !== 'ANNUAL' || leave.status !== 'APPROVED') return false;
      return dateColumnToKey(leave.startDate).slice(0, 4) === String(year);
    })
    .reduce((sum, leave) => sum + requestedDays(leave), 0);

  const entitlement = annualLeaveDays ?? null;
  return {
    entitlement,
    taken,
    remaining: entitlement === null ? null : entitlement - taken,
  };
}

/** Inclusive `{ fromKey, toKey }` for a request — handy for conflict checks. */
export function leaveRange(request: Pick<LeaveLike, 'startDate' | 'endDate'>): {
  fromKey: string;
  toKey: string;
} {
  return {
    fromKey: dateColumnToKey(request.startDate),
    toKey: dateColumnToKey(request.endDate),
  };
}

/** Date key helper re-exported so callers don't reach into datetime directly. */
export function todayKey(now: DateInput, timeZone: string): string {
  return clinicDateKey(now, timeZone);
}

export { dateKeyAdd };
