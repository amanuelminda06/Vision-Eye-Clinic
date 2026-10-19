import { prisma } from '@/lib/prisma';
import { clinicDateKey, combineDateAndTime, dateKeyAdd, DEFAULT_TIMEZONE } from '@/lib/datetime';
import { getSettingsMap, settingsFromMap, timezoneFrom } from '@/modules/settings';
import type { LeaveLike } from './logic';
import type { ShiftLike } from './logic';
import type { WorkingDay } from './logic';

/** HR seam contract: an appointment clashing with a staff leave window. */
export type AppointmentConflict = {
  id: string;
  title: string;
  startsAt: Date;
};

// ---------------------------------------------------------------------------
// Builders / settings
// ---------------------------------------------------------------------------

export type WorkingSchedule = {
  timeZone: string;
  /** Indexed by weekday (0 = Sunday) so it feeds `workingMinutesBetween`. */
  hours: ((WorkingDay & { key: string }) | undefined)[];
};

/** Clinic timezone + working-hours grid, ready for the client request-age widget. */
export async function getWorkingSchedule(): Promise<WorkingSchedule> {
  const map = await getSettingsMap();
  const timeZone = timezoneFrom(map);
  const values = settingsFromMap(map);
  const indexByKey: Record<string, number> = {
    sun: 0,
    mon: 1,
    tue: 2,
    wed: 3,
    thu: 4,
    fri: 5,
    sat: 6,
  };
  const hours = new Array<WorkingDay & { key: string }>(7);
  for (const [key, day] of Object.entries(values.working_hours)) {
    hours[indexByKey[key]] = { key, open: day.open, close: day.close, closed: day.closed };
  }
  return { timeZone, hours };
}

// ---------------------------------------------------------------------------
// Appointments
// ---------------------------------------------------------------------------

export type AppointmentRow = {
  id: string;
  patientId: string;
  patientName: string;
  patientPhoneE164: string | null;
  doctorId: string;
  doctorName: string;
  serviceId: string | null;
  serviceName: string | null;
  startsAt: Date;
  endsAt: Date;
  status: string;
  createdFrom: string;
  cancelReason: string | null;
  notes: string | null;
  createdBy: string;
  createdAt: Date;
  updatedAt: Date;
};

const APPOINTMENT_SELECT = {
  id: true,
  patientId: true,
  doctorId: true,
  serviceId: true,
  startsAt: true,
  endsAt: true,
  status: true,
  createdFrom: true,
  cancelReason: true,
  notes: true,
  createdBy: true,
  createdAt: true,
  updatedAt: true,
  patient: { select: { fullName: true, phoneE164: true } },
  doctor: { select: { fullName: true } },
  service: { select: { name: true } },
} as const;

type AppointmentSelectRow = {
  id: string;
  patientId: string;
  doctorId: string;
  serviceId: string | null;
  startsAt: Date;
  endsAt: Date;
  status: string;
  createdFrom: string;
  cancelReason: string | null;
  notes: string | null;
  createdBy: string;
  createdAt: Date;
  updatedAt: Date;
  patient: { fullName: string; phoneE164: string | null };
  doctor: { fullName: string };
  service: { name: string } | null;
};

function decorateAppointment(row: AppointmentSelectRow): AppointmentRow {
  return {
    ...row,
    patientName: row.patient.fullName,
    patientPhoneE164: row.patient.phoneE164,
    doctorName: row.doctor.fullName,
    serviceName: row.service?.name ?? null,
  };
}

export async function listAppointments(params: {
  from?: Date;
  to?: Date;
  doctorId?: string;
  patientId?: string;
  status?: string;
}): Promise<AppointmentRow[]> {
  const rows = await prisma.appointment.findMany({
    where: {
      ...(params.doctorId ? { doctorId: params.doctorId } : {}),
      ...(params.patientId ? { patientId: params.patientId } : {}),
      ...(params.status ? { status: params.status as never } : {}),
      ...(params.from || params.to
        ? {
            startsAt: {
              ...(params.from ? { gte: params.from } : {}),
              ...(params.to ? { lt: params.to } : {}),
            },
          }
        : {}),
    },
    select: APPOINTMENT_SELECT,
    orderBy: { startsAt: 'asc' },
    take: 500,
  });
  return (rows as AppointmentSelectRow[]).map(decorateAppointment);
}

export async function getAppointment(id: string): Promise<AppointmentRow | null> {
  const row = await prisma.appointment.findUnique({ where: { id }, select: APPOINTMENT_SELECT });
  return row ? decorateAppointment(row as AppointmentSelectRow) : null;
}

export async function countAppointmentsOnDay(dayKey: string): Promise<number> {
  const settings = await getSettingsMap();
  const timeZone = timezoneFrom(settings);
  const from = combineDateAndTime(dayKey, '00:00', timeZone);
  const to = combineDateAndTime(dateKeyAdd(dayKey, 1), '00:00', timeZone);
  return prisma.appointment.count({
    where: {
      startsAt: { gte: from, lt: to },
      status: { in: ['SCHEDULED', 'CONFIRMED'] as never },
    },
  });
}

export async function listPatientAppointments(patientId: string): Promise<AppointmentRow[]> {
  return listAppointments({ patientId, status: undefined });
}

export async function countPatientAppointments(patientId: string): Promise<number> {
  return prisma.appointment.count({ where: { patientId } });
}

/**
 * Real implementation of the HR seam: active appointments overlapping
 * `[from, to)` for one doctor, with a human title for the leave screen.
 * The barrel keeps exporting this, so the HR module needs no changes.
 */
export async function getStaffAppointmentsInPeriod(
  staffId: string,
  from: Date,
  to: Date,
): Promise<AppointmentConflict[]> {
  const rows = await prisma.appointment.findMany({
    where: {
      doctorId: staffId,
      status: { in: ['SCHEDULED', 'CONFIRMED'] as never },
      startsAt: { lt: to },
      endsAt: { gt: from },
    },
    select: { id: true, startsAt: true, patient: { select: { fullName: true } } },
    orderBy: { startsAt: 'asc' },
    take: 100,
  });
  return rows.map((row) => ({
    id: row.id,
    title: row.patient.fullName,
    startsAt: row.startsAt,
  }));
}

export async function countStaffAppointmentsInPeriod(
  staffId: string,
  from: Date,
  to: Date,
): Promise<number> {
  return (await getStaffAppointmentsInPeriod(staffId, from, to)).length;
}

// ---------------------------------------------------------------------------
// Follow-ups
// ---------------------------------------------------------------------------

export type FollowUpRow = {
  id: string;
  patientId: string;
  patientName: string;
  patientPhoneE164: string | null;
  dueDate: Date;
  reason: string | null;
  status: string;
  createdFromAppointmentId: string | null;
  createdBy: string;
  createdAt: Date;
};

const FOLLOW_UP_SELECT = {
  id: true,
  patientId: true,
  dueDate: true,
  reason: true,
  status: true,
  createdFromAppointmentId: true,
  createdBy: true,
  createdAt: true,
  patient: { select: { fullName: true, phoneE164: true } },
} as const;

type FollowUpSelectRow = {
  id: string;
  patientId: string;
  dueDate: Date;
  reason: string | null;
  status: string;
  createdFromAppointmentId: string | null;
  createdBy: string;
  createdAt: Date;
  patient: { fullName: string; phoneE164: string | null };
};

function decorateFollowUp(row: FollowUpSelectRow): FollowUpRow {
  return {
    ...row,
    patientName: row.patient.fullName,
    patientPhoneE164: row.patient.phoneE164,
  };
}

export async function listFollowUps(params: {
  status?: string;
  patientId?: string;
  dueBeforeKey?: string;
  fallbackTz?: string;
}): Promise<FollowUpRow[]> {
  const settings = await getSettingsMap();
  const timeZone = params.fallbackTz ?? timezoneFrom(settings);
  const rows = await prisma.followUp.findMany({
    where: {
      ...(params.status ? { status: params.status as never } : {}),
      ...(params.patientId ? { patientId: params.patientId } : {}),
      ...(params.dueBeforeKey
        ? { dueDate: { lt: combineDateAndTime(params.dueBeforeKey, '00:00', timeZone) } }
        : {}),
    },
    select: FOLLOW_UP_SELECT,
    orderBy: { dueDate: 'asc' },
    take: 300,
  });
  return (rows as FollowUpSelectRow[]).map(decorateFollowUp);
}

export async function getFollowUp(id: string): Promise<FollowUpRow | null> {
  const row = await prisma.followUp.findUnique({ where: { id }, select: FOLLOW_UP_SELECT });
  return row ? decorateFollowUp(row as FollowUpSelectRow) : null;
}

/** Open follow-ups due strictly before the given local day. */
export async function countOpenFollowUpsOverdue(todayKey: string): Promise<number> {
  const settings = await getSettingsMap();
  const timeZone = timezoneFrom(settings);
  const before = combineDateAndTime(todayKey, '00:00', timeZone);
  return prisma.followUp.count({
    where: { status: 'OPEN', dueDate: { lt: before } },
  });
}

// ---------------------------------------------------------------------------
// Supporting data for schedule advisories
// ---------------------------------------------------------------------------

/** Shifts of one doctor overlapping `[from, to)` (as pure shift records). */
export async function getDoctorShiftsForCoverage(
  doctorId: string,
  from: Date,
  to: Date,
): Promise<ShiftLike[]> {
  const rows = await prisma.shift.findMany({
    where: { staffId: doctorId, startsAt: { lt: to }, endsAt: { gt: from } },
    select: { id: true, staffId: true, startsAt: true, endsAt: true },
    take: 200,
  });
  return rows.map((row) => ({
    id: row.id,
    staffId: row.staffId,
    startsAt: row.startsAt,
    endsAt: row.endsAt,
  }));
}

/** Approved leave covering any date in the range for the given staff. */
export async function getApprovedLeaveInRange(
  staffIds: string[],
  fromKey: string,
  toKey: string,
): Promise<LeaveLike[]> {
  if (staffIds.length === 0) return [];
  const settings = await getSettingsMap();
  const timeZone = timezoneFrom(settings);
  const from = combineDateAndTime(fromKey, '00:00', timeZone);
  const to = combineDateAndTime(dateKeyAdd(toKey, 1), '00:00', timeZone);
  const rows = await prisma.leaveRequest.findMany({
    where: {
      status: 'APPROVED',
      staffId: { in: staffIds },
      startDate: { lte: to },
      endDate: { gte: from },
    },
    select: {
      id: true,
      staffId: true,
      type: true,
      status: true,
      startDate: true,
      endDate: true,
      halfDay: true,
    },
    take: 500,
  });
  return rows.map((row) => ({
    id: row.id,
    staffId: row.staffId,
    type: row.type,
    status: row.status,
    startDate: row.startDate,
    endDate: row.endDate,
    halfDay: row.halfDay,
  }));
}

export { clinicDateKey, DEFAULT_TIMEZONE, timezoneFrom };
