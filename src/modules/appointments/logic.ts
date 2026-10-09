import { clinicDateKey, combineDateAndTime, dateKeyAdd, DEFAULT_TIMEZONE } from '@/lib/datetime';
import { dateKeysBetween } from '@/modules/hr/shifts';
import { APPOINTMENT_STATUSES, FOLLOW_UP_PRESETS } from './constants';
import type { AppointmentStatusValue } from './constants';

export type AppointmentLike = {
  id: string;
  doctorId: string;
  startsAt: Date;
  endsAt: Date;
  status: string;
};

export type PatientLike = {
  id: string;
  phoneE164: string;
};

export type ShiftLike = {
  id: string;
  staffId: string;
  startsAt: Date;
  endsAt: Date;
};

export type LeaveLike = {
  id: string;
  staffId: string;
  type: string;
  status: string;
  startDate: Date | string;
  endDate: Date | string;
  halfDay: boolean;
};

// ---------------------------------------------------------------------------
// Status transitions
// ---------------------------------------------------------------------------

/** Lawful non-terminal moves: SCHEDULED -> CONFIRMED -> COMPLETED, plus cancel/no-show. */
const BASE_TRANSITIONS: Record<AppointmentStatusValue, readonly AppointmentStatusValue[]> = {
  SCHEDULED: ['CONFIRMED', 'CANCELLED', 'NO_SHOW'],
  CONFIRMED: ['COMPLETED', 'CANCELLED', 'NO_SHOW'],
  COMPLETED: [],
  NO_SHOW: [],
  CANCELLED: [],
};

const TERMINAL: readonly AppointmentStatusValue[] = ['COMPLETED', 'NO_SHOW', 'CANCELLED'];

export function isTerminalStatus(status: string): boolean {
  return (TERMINAL as readonly string[]).includes(status);
}

/**
 * Whether `from -> to` is allowed. Terminal states are frozen for everyone;
 * moving out of them (a correction) requires the OWNER (`ownerOverride`) and
 * is always audited by the action.
 */
export function transitionAllowed(
  from: string,
  to: string,
  opts: { ownerOverride?: boolean } = {},
): boolean {
  if (from === to) return false;
  if (!(APPOINTMENT_STATUSES as readonly string[]).includes(from)) return false;
  if (!(APPOINTMENT_STATUSES as readonly string[]).includes(to)) return false;
  const fromStatus = from as AppointmentStatusValue;
  if (isTerminalStatus(fromStatus)) return opts.ownerOverride === true;
  return (BASE_TRANSITIONS[fromStatus] as readonly string[]).includes(to);
}

// ---------------------------------------------------------------------------
// Doctor double-booking (warned, not blocked)
// ---------------------------------------------------------------------------

function isActiveStatus(status: string): boolean {
  return status === 'SCHEDULED' || status === 'CONFIRMED';
}

/** Overlapping active appointments for the same doctor — the double-booking warning. */
export function findDoctorDoubleBookings<T extends AppointmentLike>(
  existing: T[],
  candidate: { id?: string; doctorId: string; startsAt: Date; endsAt: Date },
): T[] {
  return existing.filter(
    (appt) =>
      appt.id !== candidate.id &&
      appt.doctorId === candidate.doctorId &&
      isActiveStatus(appt.status) &&
      appt.startsAt.getTime() < candidate.endsAt.getTime() &&
      candidate.startsAt.getTime() < appt.endsAt.getTime(),
  );
}

/**
 * `true` when the candidate window sits fully inside one of the doctor's
 * shifts (overnight shifts work because both instants are UTC).
 */
export function isInsideShift<T extends ShiftLike>(
  shifts: T[],
  startsAt: Date,
  endsAt: Date,
): boolean {
  return shifts.some(
    (shift) =>
      shift.startsAt.getTime() <= startsAt.getTime() && shift.endsAt.getTime() >= endsAt.getTime(),
  );
}

function leaveCoversDateKey(leave: LeaveLike, dateKey: string): boolean {
  if (leave.status !== 'APPROVED') return false;
  const startKey = String(leave.startDate).slice(0, 10);
  const endKey = String(leave.endDate).slice(0, 10);
  return dateKey >= startKey && dateKey <= endKey;
}

/**
 * Non-blocking scheduling advisories: the appointment window is not fully
 * inside one of the doctor's shifts and/or falls on approved-leave days.
 * Returns a list of stable keys (`outsideShift`, `onLeave`) — never throws.
 */
export function appointmentCoverageWarnings<T extends ShiftLike, L extends LeaveLike>(
  candidate: { doctorId: string; startsAt: Date; endsAt: Date },
  shifts: T[],
  leaves: L[],
  timeZone: string = DEFAULT_TIMEZONE,
): string[] {
  const warnings: string[] = [];
  if (!isInsideShift(shifts, candidate.startsAt, candidate.endsAt)) warnings.push('outsideShift');

  const startKey = clinicDateKey(candidate.startsAt, timeZone);
  const endKey = clinicDateKey(candidate.endsAt, timeZone);
  const onLeave = dateKeysBetween(startKey, endKey, 30).some((dateKey) =>
    leaves.some(
      (leave) => leave.staffId === candidate.doctorId && leaveCoversDateKey(leave, dateKey),
    ),
  );
  if (onLeave) warnings.push('onLeave');
  return warnings;
}

// ---------------------------------------------------------------------------
// Request conversion
// ---------------------------------------------------------------------------

/** Best-effort match of a website request's phone to an existing patient. */
export function resolvePatientMatch<T extends PatientLike>(
  patients: T[],
  phoneE164: string,
): T | null {
  return patients.find((patient) => patient.phoneE164 === phoneE164) ?? null;
}

// ---------------------------------------------------------------------------
// Dates / times
// ---------------------------------------------------------------------------

/** Wall-clock `{start,end}` (HH:mm) for a request's preferred time window. */
export function slotForTimeWindow(window: string | null | undefined): {
  start: string;
  end: string;
} {
  const slots = [
    { key: 'any', start: '09:00', end: '10:00' },
    { key: 'morning', start: '09:00', end: '10:00' },
    { key: 'afternoon', start: '14:00', end: '15:00' },
    { key: 'evening', start: '16:00', end: '17:00' },
  ];
  const key = String(window ?? '')
    .trim()
    .toLowerCase();
  const slot = slots.find((candidate) => candidate.key === key) ?? slots[0];
  return { start: slot.start, end: slot.end };
}

/** UTC instants for a clinic-local day/start + duration in minutes. */
export function appointmentInstants(
  dateKey: string,
  startTime: string,
  durationMin: number,
  timeZone: string = DEFAULT_TIMEZONE,
): { startsAt: Date; endsAt: Date } {
  const startsAt = combineDateAndTime(dateKey, startTime, timeZone);
  const endsAt = new Date(startsAt.getTime() + Math.max(1, durationMin) * 60_000);
  return { startsAt, endsAt };
}

function dateKeyAddMonths(baseKey: string, months: number): string {
  const [y, m, d] = baseKey.split('-').map(Number);
  const target = new Date(Date.UTC(y, m - 1 + months, 1));
  const lastDay = new Date(
    Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0),
  ).getUTCDate();
  const day = Math.min(d, lastDay);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${target.getUTCFullYear()}-${pad(target.getUTCMonth() + 1)}-${pad(day)}`;
}

/** `YYYY-MM-DD` due date for a follow-up preset (2 weeks / 1/3/6 months / custom). */
export function followUpDueKey(
  baseKey: string,
  preset: (typeof FOLLOW_UP_PRESETS)[number] | string,
  customKey?: string,
): string {
  switch (preset) {
    case '2w':
      return dateKeyAdd(baseKey, 14);
    case '1m':
      return dateKeyAddMonths(baseKey, 1);
    case '3m':
      return dateKeyAddMonths(baseKey, 3);
    case '6m':
      return dateKeyAddMonths(baseKey, 6);
    case 'custom':
      return customKey ? String(customKey).slice(0, 10) : baseKey;
    default:
      return baseKey;
  }
}

export type WorkingDay = { open: string; close: string; closed: boolean };

/**
 * Clinic working minutes between two instants, computed from the settings
 * working-hours grid (indexed by weekday, 0 = Sunday). Closed days/hours don't
 * count; the iteration is capped to bound work on absurdly old windows.
 */
export function workingMinutesBetween(
  from: Date,
  to: Date,
  hours: readonly (WorkingDay | undefined)[],
  timeZone: string = DEFAULT_TIMEZONE,
): number {
  if (from.getTime() >= to.getTime()) return 0;
  let total = 0;
  let cursor = from;
  let guard = 0;
  while (cursor.getTime() < to.getTime() && guard < 400) {
    const day = hours[dayOfWeek(cursor, timeZone)];
    if (day && !day.closed) {
      const dayKey = clinicDateKey(cursor, timeZone);
      const openAt = combineDateAndTime(dayKey, day.open, timeZone);
      const closeAt = combineDateAndTime(dayKey, day.close, timeZone);
      const windowStart = Math.max(from.getTime(), openAt.getTime());
      const windowEnd = Math.min(to.getTime(), closeAt.getTime());
      if (windowEnd > windowStart) total += windowEnd - windowStart;
    }
    const nextKey = dateKeyAdd(clinicDateKey(cursor, timeZone), 1);
    cursor = combineDateAndTime(nextKey, '00:00', timeZone);
    guard += 1;
  }
  return Math.floor(total / 60_000);
}

function dayOfWeek(instant: Date, timeZone: string): number {
  const [y, m, d] = clinicDateKey(instant, timeZone).split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
