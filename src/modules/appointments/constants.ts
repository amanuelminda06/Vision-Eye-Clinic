export const APPOINTMENT_STATUSES = [
  'SCHEDULED',
  'CONFIRMED',
  'COMPLETED',
  'NO_SHOW',
  'CANCELLED',
] as const;
export type AppointmentStatusValue = (typeof APPOINTMENT_STATUSES)[number];

/** `t()` key inside the `appointments` namespace for each status. */
export const APPOINTMENT_STATUS_LABEL_KEYS: Record<AppointmentStatusValue, string> = {
  SCHEDULED: 'statusScheduled',
  CONFIRMED: 'statusConfirmed',
  COMPLETED: 'statusCompleted',
  NO_SHOW: 'statusNoShow',
  CANCELLED: 'statusCancelled',
};

export function isAppointmentStatus(value: unknown): value is AppointmentStatusValue {
  return typeof value === 'string' && (APPOINTMENT_STATUSES as readonly string[]).includes(value);
}

export const APPOINTMENT_SOURCES = ['STAFF', 'WEBSITE_REQUEST'] as const;
export type AppointmentSourceValue = (typeof APPOINTMENT_SOURCES)[number];

export const APPOINTMENT_SOURCE_LABEL_KEYS: Record<AppointmentSourceValue, string> = {
  STAFF: 'sourceStaff',
  WEBSITE_REQUEST: 'sourceWebsite',
};

export const REQUEST_STATUSES = ['NEW', 'CONTACTED', 'CONVERTED', 'DECLINED', 'SPAM'] as const;
export type RequestStatusValue = (typeof REQUEST_STATUSES)[number];

export const REQUEST_STATUS_LABEL_KEYS: Record<RequestStatusValue, string> = {
  NEW: 'statusNew',
  CONTACTED: 'statusContacted',
  CONVERTED: 'statusConverted',
  DECLINED: 'statusDeclined',
  SPAM: 'statusSpam',
};

export const FOLLOW_UP_STATUSES = ['OPEN', 'DONE', 'CANCELLED'] as const;
export type FollowUpStatusValue = (typeof FOLLOW_UP_STATUSES)[number];

export const FOLLOW_UP_STATUS_LABEL_KEYS: Record<FollowUpStatusValue, string> = {
  OPEN: 'statusOpen',
  DONE: 'statusDone',
  CANCELLED: 'statusCancelled',
};

/** Compact duration presets for the appointment form (minutes). */
export const DURATIONS = [15, 30, 45, 60, 90] as const;
export const DEFAULT_DURATION = 30;

/** Clinic-local wall-clock slots a website request maps to when converted. */
export type TimeWindow = { key: string; labelKey: string; start: string; end: string };

export const TIME_WINDOWS: readonly TimeWindow[] = [
  { key: 'any', labelKey: 'windowAny', start: '09:00', end: '10:00' },
  { key: 'morning', labelKey: 'windowMorning', start: '09:00', end: '10:00' },
  { key: 'afternoon', labelKey: 'windowAfternoon', start: '14:00', end: '15:00' },
  { key: 'evening', labelKey: 'windowEvening', start: '16:00', end: '17:00' },
];

/** Follow-up due presets offered when completing an appointment. */
export const FOLLOW_UP_PRESETS = ['2w', '1m', '3m', '6m', 'custom'] as const;
export type FollowUpPreset = (typeof FOLLOW_UP_PRESETS)[number];

/** Requests older (in clinic working time) than this are flagged red. */
export const REQUEST_WAIT_WORKING_MINUTES = 120;

export const MAX_APPT_NOTES = 300;
export const MAX_CANCEL_REASON = 300;
export const MAX_FOLLOW_UP_REASON = 300;
export const MAX_REQUEST_NAME = 100;
export const MAX_REQUEST_MESSAGE = 500;
export const MAX_REQUEST_INTEREST = 200;
export const MAX_REQUEST_DATE_DAYS_AHEAD = 180;

export const APPT_TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;
export const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
