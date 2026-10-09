import {
  clinicDateKey,
  clinicTimeKey,
  combineDateAndTime,
  dateKeyAdd,
  endOfDay,
  startOfDay,
} from '@/lib/datetime';
import { isShiftActiveAt, nextShiftAfter, type ShiftLike } from './shifts';
import { leaveCoversDate, type LeaveLike } from './leave';

/**
 * Public "on duty now" projection (no UI yet).
 *
 * This is the ONLY shape that may leave the clinic for the public website. It
 * is a strict allow-list projection: never phone, email, leave reasons, shift
 * notes, ids of internal records or any other private field.
 */

export type PublicDoctorStatus = 'ON_DUTY_NOW' | 'ON_LEAVE' | 'NOT_ON_DUTY';

export type PublicDoctor = {
  id: string;
  name: string;
  specialty: string | null;
  photoUrl: string | null;
  status: PublicDoctorStatus;
  /** Today's shift ranges as clinic-local "HH:mm". */
  todayShifts: Array<{ start: string; end: string }>;
  /** ISO instant of the next shift start, or null. */
  nextShiftStartsAt: string | null;
};

/** The public subset of a Staff row — only these fields, nothing else. */
export type PublicStaffInput = {
  id: string;
  fullName: string;
  specialty: string | null;
  photoUrl: string | null;
  isDoctor: boolean;
  showOnWebsite: boolean;
  active: boolean;
};

/** A doctor is published only when flagged and still active. */
export function isPublicDoctor(staff: PublicStaffInput): boolean {
  return staff.isDoctor && staff.showOnWebsite && staff.active;
}

export type PublicDoctorInput = {
  staff: PublicStaffInput;
  shifts: ShiftLike[];
  leaves: LeaveLike[];
  now: Date;
  timeZone: string;
};

/**
 * Builds the public status for one doctor. Priority: an approved leave today
 * wins, then a currently-running shift, then not on duty.
 */
export function buildPublicDoctorStatus({
  staff,
  shifts,
  leaves,
  now,
  timeZone,
}: PublicDoctorInput): PublicDoctor {
  const todayKey = clinicDateKey(now, timeZone);
  const ownShifts = shifts.filter((shift) => shift.staffId === staff.id);

  const onLeave = leaves.some(
    (leave) =>
      leave.staffId === staff.id && leave.status === 'APPROVED' && leaveCoversDate(leave, todayKey),
  );

  const activeShift = ownShifts.find((shift) => isShiftActiveAt(shift, now));
  const status: PublicDoctorStatus = onLeave
    ? 'ON_LEAVE'
    : activeShift
      ? 'ON_DUTY_NOW'
      : 'NOT_ON_DUTY';

  const dayStart = startOfDay(now, timeZone);
  const dayEnd = endOfDay(now, timeZone);
  const todayShifts = ownShifts
    .filter(
      (shift) =>
        shift.startsAt.getTime() < dayEnd.getTime() && shift.endsAt.getTime() > dayStart.getTime(),
    )
    .sort((a, b) => a.startsAt.getTime() - b.startsAt.getTime())
    .map((shift) => ({
      start: clinicTimeKey(shift.startsAt, timeZone),
      end: clinicTimeKey(shift.endsAt, timeZone),
    }));

  const next = activeShift ?? nextShiftAfter(ownShifts, now);

  return {
    id: staff.id,
    name: staff.fullName,
    specialty: staff.specialty,
    photoUrl: staff.photoUrl,
    status,
    todayShifts,
    nextShiftStartsAt: next ? next.startsAt.toISOString() : null,
  };
}

/** Template for tests: a date key N days from `now` (clinic local). */
export function contextDateKeys(now: Date, timeZone: string, days: number): string[] {
  const today = clinicDateKey(now, timeZone);
  return Array.from({ length: days }, (_, i) => dateKeyAdd(today, i));
}

/** Used by the public route to point at the start of a given day. */
export function dayStartInstants(dateKey: string, timeZone: string): { from: Date; to: Date } {
  return {
    from: combineDateAndTime(dateKey, '00:00', timeZone),
    to: combineDateAndTime(dateKeyAdd(dateKey, 1), '00:00', timeZone),
  };
}
