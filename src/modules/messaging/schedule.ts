import {
  clinicDateKey,
  combineDateAndTime,
  dateKeyAdd,
  endOfDay,
  DEFAULT_TIMEZONE,
} from '@/lib/datetime';

/**
 * Pure dueTime/sendBy/expiresAt math for every automatic template. All
 * wall-clock values are interpreted in the clinic timezone and stored as UTC
 * instants. No DB here — unit-tested directly (including midnight crossings).
 */

export type MessagingSettings = {
  timeZone: string;
  /** "HH:mm" clinic-local. */
  reminderSendTime: string;
  reminderSendByOffsetMinutes: number;
  followupLeadDays: number;
  recallMonths: number;
};

export const DEFAULT_MESSAGING_SETTINGS: MessagingSettings = {
  timeZone: DEFAULT_TIMEZONE,
  reminderSendTime: '14:00',
  reminderSendByOffsetMinutes: 60,
  followupLeadDays: 3,
  recallMonths: 12,
};

export type AppointmentLike = {
  id: string;
  startsAt: Date;
  createdAt: Date;
};

export type Schedule = {
  dueAt: Date;
  sendBy: Date | null;
  expiresAt: Date | null;
};

const DAY_MS = 24 * 60 * 60 * 1000;

function laterOf(a: Date, b: Date): Date {
  return a.getTime() >= b.getTime() ? a : b;
}

/** Appointment confirmation: due now, must go out by the end of the day. */
export function appointmentConfirmationSchedule(now: Date, settings: MessagingSettings): Schedule {
  return { dueAt: now, sendBy: endOfDay(now, settings.timeZone), expiresAt: null };
}

/**
 * Appointment reminder: the day before at `reminder_send_time`, expiring at the
 * appointment start. Late bookings (created after dueAt) fall due immediately.
 * Returns null when the appointment has already started.
 */
export function appointmentReminderSchedule(
  appointment: AppointmentLike,
  settings: MessagingSettings,
  now: Date,
): Schedule | null {
  if (appointment.startsAt.getTime() <= now.getTime()) return null;
  const dueDayKey = dateKeyAdd(clinicDateKey(appointment.startsAt, settings.timeZone), -1);
  let dueAt = combineDateAndTime(dueDayKey, settings.reminderSendTime, settings.timeZone);
  if (appointment.createdAt.getTime() > dueAt.getTime() || dueAt.getTime() < now.getTime()) {
    dueAt = now;
  }
  const sendBy = new Date(
    appointment.startsAt.getTime() - Math.max(0, settings.reminderSendByOffsetMinutes) * 60_000,
  );
  return { dueAt, sendBy, expiresAt: appointment.startsAt };
}

/** Morning-of reminder: due 08:00 clinic time, expires at the appointment. */
export function appointmentSameDaySchedule(
  appointment: AppointmentLike,
  settings: MessagingSettings,
  now: Date,
): Schedule | null {
  if (appointment.startsAt.getTime() <= now.getTime()) return null;
  const todayKey = clinicDateKey(now, settings.timeZone);
  let dueAt = combineDateAndTime(todayKey, '08:00', settings.timeZone);
  if (dueAt.getTime() < now.getTime()) dueAt = now;
  return { dueAt, sendBy: appointment.startsAt, expiresAt: appointment.startsAt };
}

/** No-show follow-up: next morning 09:00, expiring after three days. */
export function noShowSchedule(now: Date, settings: MessagingSettings): Schedule {
  const dueKey = dateKeyAdd(clinicDateKey(now, settings.timeZone), 1);
  const dueAt = combineDateAndTime(dueKey, '09:00', settings.timeZone);
  return { dueAt, sendBy: null, expiresAt: new Date(dueAt.getTime() + 3 * DAY_MS) };
}

/** Follow-up reminder: `followup_lead_days` before the due date, expires +7d. */
export function followUpSchedule(dueDate: Date, settings: MessagingSettings, now: Date): Schedule {
  const dueKey = clinicDateKey(dueDate, settings.timeZone);
  const startKey = dateKeyAdd(dueKey, -settings.followupLeadDays);
  let dueAt = combineDateAndTime(startKey, '09:00', settings.timeZone);
  if (dueAt.getTime() < now.getTime()) dueAt = now;
  const expiresAt = combineDateAndTime(dateKeyAdd(dueKey, 7), '23:59', settings.timeZone);
  return { dueAt, sendBy: null, expiresAt };
}

/** Thank-you: the morning after a completed appointment (09:00). */
export function thankYouSchedule(
  appointment: AppointmentLike,
  settings: MessagingSettings,
): Schedule {
  const dayKey = dateKeyAdd(clinicDateKey(appointment.startsAt, settings.timeZone), 1);
  return {
    dueAt: combineDateAndTime(dayKey, '09:00', settings.timeZone),
    sendBy: null,
    expiresAt: null,
  };
}

/** Birthday: 08:00 on the day, expiring at the next local midnight. */
export function birthdaySchedule(now: Date, settings: MessagingSettings): Schedule {
  const todayKey = clinicDateKey(now, settings.timeZone);
  return {
    dueAt: combineDateAndTime(todayKey, '08:00', settings.timeZone),
    sendBy: null,
    expiresAt: combineDateAndTime(dateKeyAdd(todayKey, 1), '00:00', settings.timeZone),
  };
}

/** Recall: due immediately, valid for 30 days. */
export function recallSchedule(now: Date): Schedule {
  return { dueAt: now, sendBy: null, expiresAt: new Date(now.getTime() + 30 * DAY_MS) };
}

/** Immediate task (welcome, schedule change, manual): due now. */
export function immediateSchedule(now: Date): Schedule {
  return { dueAt: now, sendBy: null, expiresAt: null };
}

/** `YYYY-MM` period key used to make monthly recall generation idempotent. */
export function recallPeriodKey(now: Date, timeZone: string): string {
  return clinicDateKey(now, timeZone).slice(0, 7);
}

export { laterOf };
