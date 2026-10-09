export const LEAVE_TYPES = ['ANNUAL', 'SICK', 'UNPAID', 'OTHER'] as const;
export type LeaveTypeValue = (typeof LEAVE_TYPES)[number];

/** `t()` key inside the `hr` namespace for each leave type. */
export const LEAVE_TYPE_LABEL_KEYS: Record<LeaveTypeValue, string> = {
  ANNUAL: 'typeAnnual',
  SICK: 'typeSick',
  UNPAID: 'typeUnpaid',
  OTHER: 'typeOther',
};

export function isLeaveType(value: unknown): value is LeaveTypeValue {
  return typeof value === 'string' && (LEAVE_TYPES as readonly string[]).includes(value);
}

export const LEAVE_STATUSES = ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'] as const;
export type LeaveStatusValue = (typeof LEAVE_STATUSES)[number];

export const LEAVE_STATUS_LABEL_KEYS: Record<LeaveStatusValue, string> = {
  REQUESTED: 'statusRequested',
  APPROVED: 'statusApproved',
  REJECTED: 'statusRejected',
  CANCELLED: 'statusCancelled',
};

export function isLeaveStatus(value: unknown): value is LeaveStatusValue {
  return typeof value === 'string' && (LEAVE_STATUSES as readonly string[]).includes(value);
}

export const SHIFT_SOURCES = ['TEMPLATE', 'MANUAL'] as const;
export type ShiftSourceValue = (typeof SHIFT_SOURCES)[number];

/** Weekday labels, indexed 0 = Sunday … 6 = Saturday (Date.getUTCDay()). */
export const WEEKDAY_LABEL_KEYS = [
  'daySun',
  'dayMon',
  'dayTue',
  'dayWed',
  'dayThu',
  'dayFri',
  'daySat',
] as const;

/** Deterministic per-person colours for the schedule grid. */
export const SHIFT_COLORS = [
  '#2563eb',
  '#0d9488',
  '#db2777',
  '#d97706',
  '#7c3aed',
  '#0891b2',
  '#65a30d',
  '#dc2626',
  '#4f46e5',
  '#059669',
] as const;

export function colorForIndex(index: number): string {
  return SHIFT_COLORS[((index % SHIFT_COLORS.length) + SHIFT_COLORS.length) % SHIFT_COLORS.length];
}

export const MAX_NOTE = 200;
export const MAX_REASON = 300;
export const MAX_DECISION_NOTE = 300;
export const MAX_RANGE_DAYS = 62;
export const MAX_TEMPLATE_SHIFTS = 400;
export const MAX_TEMPLATE_STAFF = 50;

/** `HH:mm` 24-hour clinic-local time. */
export const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;
export const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
