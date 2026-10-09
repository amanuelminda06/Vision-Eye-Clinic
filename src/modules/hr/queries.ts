import { prisma } from '@/lib/prisma';
import { clinicDateKey, startOfDay } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { countStaffAppointmentsInPeriod } from '@/modules/appointments';
import { dateColumnToKey, type ShiftLike } from './shifts';
import { leaveBalance, type LeaveBalance, type LeaveLike } from './leave';
import { buildPublicDoctorStatus, type PublicDoctor, type PublicStaffInput } from './public-status';

function dateKeyToUtcDate(dateKey: string): Date {
  return new Date(`${dateKey}T00:00:00.000Z`);
}

export type StaffOption = {
  id: string;
  fullName: string;
  isDoctor: boolean;
  specialty: string | null;
};

/** Active staff for pickers (doctors + everyone, depending on caller). */
export async function listStaffOptions(): Promise<StaffOption[]> {
  return prisma.staff.findMany({
    where: { active: true },
    select: { id: true, fullName: true, isDoctor: true, specialty: true },
    orderBy: [{ isDoctor: 'desc' }, { fullName: 'asc' }],
  });
}

export async function getStaffOption(id: string): Promise<StaffOption | null> {
  return prisma.staff.findUnique({
    where: { id },
    select: { id: true, fullName: true, isDoctor: true, specialty: true },
  });
}

/** The staff link of a user account (null for owners/reception). */
export async function getUserStaffId(userId: string): Promise<string | null> {
  const user = await prisma.user.findUnique({
    where: { id: userId },
    select: { staffId: true },
  });
  return user?.staffId ?? null;
}

// ---------------------------------------------------------------------------
// Shifts
// ---------------------------------------------------------------------------

export type ShiftRow = {
  id: string;
  staffId: string;
  staffName: string;
  startsAt: Date;
  endsAt: Date;
  source: string;
  note: string | null;
  createdBy: string;
};

/** Shifts overlapping `[from, to)` for one staff member or the whole team. */
export async function listShiftsInRange(
  from: Date,
  to: Date,
  staffId?: string,
): Promise<ShiftRow[]> {
  const rows = await prisma.shift.findMany({
    where: {
      startsAt: { lt: to },
      endsAt: { gt: from },
      ...(staffId ? { staffId } : {}),
    },
    select: {
      id: true,
      staffId: true,
      startsAt: true,
      endsAt: true,
      source: true,
      note: true,
      createdBy: true,
      staff: { select: { fullName: true } },
    },
    orderBy: { startsAt: 'asc' },
  });
  return rows.map((row) => ({
    id: row.id,
    staffId: row.staffId,
    staffName: row.staff.fullName,
    startsAt: row.startsAt,
    endsAt: row.endsAt,
    source: row.source,
    note: row.note,
    createdBy: row.createdBy,
  }));
}

export async function getShift(id: string): Promise<ShiftRow | null> {
  const row = await prisma.shift.findUnique({
    where: { id },
    select: {
      id: true,
      staffId: true,
      startsAt: true,
      endsAt: true,
      source: true,
      note: true,
      createdBy: true,
      staff: { select: { fullName: true } },
    },
  });
  return row
    ? {
        id: row.id,
        staffId: row.staffId,
        staffName: row.staff.fullName,
        startsAt: row.startsAt,
        endsAt: row.endsAt,
        source: row.source,
        note: row.note,
        createdBy: row.createdBy,
      }
    : null;
}

export type TemplateRow = {
  id: string;
  staffId: string;
  staffName: string;
  weekday: number;
  startTime: string;
  endTime: string;
  validFrom: Date;
  validTo: Date | null;
};

export async function listTemplates(staffId?: string): Promise<TemplateRow[]> {
  const rows = await prisma.shiftTemplate.findMany({
    where: staffId ? { staffId } : {},
    select: {
      id: true,
      staffId: true,
      weekday: true,
      startTime: true,
      endTime: true,
      validFrom: true,
      validTo: true,
      staff: { select: { fullName: true } },
    },
    orderBy: [{ staff: { fullName: 'asc' } }, { weekday: 'asc' }, { startTime: 'asc' }],
  });
  return rows.map((row) => ({
    id: row.id,
    staffId: row.staffId,
    staffName: row.staff.fullName,
    weekday: row.weekday,
    startTime: row.startTime,
    endTime: row.endTime,
    validFrom: row.validFrom,
    validTo: row.validTo,
  }));
}

// ---------------------------------------------------------------------------
// Leave
// ---------------------------------------------------------------------------

export type LeaveRow = {
  id: string;
  staffId: string;
  staffName: string;
  type: string;
  startDate: Date;
  endDate: Date;
  halfDay: boolean;
  reason: string | null;
  status: string;
  decidedBy: string | null;
  decidedByName: string | null;
  decidedAt: Date | null;
  decisionNote: string | null;
  createdBy: string;
  createdAt: Date;
};

const LEAVE_SELECT = {
  id: true,
  staffId: true,
  type: true,
  startDate: true,
  endDate: true,
  halfDay: true,
  reason: true,
  status: true,
  decidedBy: true,
  decidedAt: true,
  decisionNote: true,
  createdBy: true,
  createdAt: true,
  staff: { select: { fullName: true } },
} as const;

async function decorateLeave(
  rows: Array<{
    id: string;
    staffId: string;
    type: string;
    startDate: Date;
    endDate: Date;
    halfDay: boolean;
    reason: string | null;
    status: string;
    decidedBy: string | null;
    decidedAt: Date | null;
    decisionNote: string | null;
    createdBy: string;
    createdAt: Date;
    staff: { fullName: string };
  }>,
): Promise<LeaveRow[]> {
  const deciderIds = [...new Set(rows.map((r) => r.decidedBy).filter((id): id is string => !!id))];
  const deciders = deciderIds.length
    ? await prisma.user.findMany({
        where: { id: { in: deciderIds } },
        select: { id: true, name: true },
      })
    : [];
  const nameById = new Map(deciders.map((u) => [u.id, u.name]));
  return rows.map((row) => ({
    ...row,
    staffName: row.staff.fullName,
    decidedByName: row.decidedBy ? (nameById.get(row.decidedBy) ?? null) : null,
  }));
}

export async function listLeaveForStaff(staffId: string): Promise<LeaveRow[]> {
  const rows = await prisma.leaveRequest.findMany({
    where: { staffId },
    select: LEAVE_SELECT,
    orderBy: { createdAt: 'desc' },
    take: 100,
  });
  return decorateLeave(rows);
}

export async function listLeaveByStatus(status: string): Promise<LeaveRow[]> {
  const rows = await prisma.leaveRequest.findMany({
    where: { status: status as never },
    select: LEAVE_SELECT,
    orderBy: { createdAt: 'asc' },
    take: 100,
  });
  return decorateLeave(rows);
}

/** Approved (and pending) leave overlapping a calendar window — team view. */
export async function listTeamLeaveInRange(fromKey: string, toKey: string): Promise<LeaveRow[]> {
  const rows = await prisma.leaveRequest.findMany({
    where: {
      status: { in: ['REQUESTED', 'APPROVED'] },
      startDate: { lte: dateKeyToUtcDate(toKey) },
      endDate: { gte: dateKeyToUtcDate(fromKey) },
    },
    select: LEAVE_SELECT,
    orderBy: { startDate: 'asc' },
    take: 500,
  });
  return decorateLeave(rows);
}

export async function getLeave(id: string): Promise<LeaveRow | null> {
  const row = await prisma.leaveRequest.findUnique({ where: { id }, select: LEAVE_SELECT });
  if (!row) return null;
  return (await decorateLeave([row]))[0];
}

/** Pending requests with a count of clashing appointments (stub: always 0). */
export type PendingLeaveRow = LeaveRow & { appointmentConflicts: number };

export async function listPendingLeaveRequests(): Promise<PendingLeaveRow[]> {
  const rows = await listLeaveByStatus('REQUESTED');
  return Promise.all(
    rows.map(async (row) => ({
      ...row,
      appointmentConflicts: await countStaffAppointmentsInPeriod(
        row.staffId,
        row.startDate,
        row.endDate,
      ),
    })),
  );
}

export async function countPendingLeave(): Promise<number> {
  return prisma.leaveRequest.count({ where: { status: 'REQUESTED' } });
}

function asLeaveLike(row: {
  id: string;
  staffId: string;
  type: string;
  status: string;
  startDate: Date;
  endDate: Date;
  halfDay: boolean;
}): LeaveLike {
  return {
    id: row.id,
    staffId: row.staffId,
    type: row.type,
    status: row.status,
    startDate: row.startDate,
    endDate: row.endDate,
    halfDay: row.halfDay,
  };
}

export async function getLeaveBalance(
  staffId: string,
  year: number,
): Promise<LeaveBalance & { annualLeaveDays: number | null }> {
  const staff = await prisma.staff.findUnique({
    where: { id: staffId },
    select: { annualLeaveDays: true },
  });
  const rows = await prisma.leaveRequest.findMany({
    where: { staffId, type: 'ANNUAL', status: 'APPROVED' },
    select: {
      id: true,
      staffId: true,
      type: true,
      status: true,
      startDate: true,
      endDate: true,
      halfDay: true,
    },
  });
  const balance = leaveBalance(staff?.annualLeaveDays ?? null, rows.map(asLeaveLike), year);
  return { ...balance, annualLeaveDays: staff?.annualLeaveDays ?? null };
}

/** Approved leave covering `dateKey`, for a set of staff ids. */
export async function listApprovedLeaveCovering(
  staffIds: string[],
  dateKey: string,
): Promise<LeaveLike[]> {
  if (staffIds.length === 0) return [];
  const day = dateKeyToUtcDate(dateKey);
  const rows = await prisma.leaveRequest.findMany({
    where: {
      status: 'APPROVED',
      staffId: { in: staffIds },
      startDate: { lte: day },
      endDate: { gte: day },
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
  });
  return rows.map(asLeaveLike);
}

// ---------------------------------------------------------------------------
// "On duty now" (dashboard widget) + public projection
// ---------------------------------------------------------------------------

async function getDoctorDutyStatuses(
  now: Date,
  timeZone: string,
  publicOnly: boolean,
): Promise<PublicDoctor[]> {
  const staff = await prisma.staff.findMany({
    where: { active: true, isDoctor: true, ...(publicOnly ? { showOnWebsite: true } : {}) },
    select: {
      id: true,
      fullName: true,
      specialty: true,
      photoUrl: true,
      isDoctor: true,
      showOnWebsite: true,
      active: true,
    },
    orderBy: { fullName: 'asc' },
  });
  if (staff.length === 0) return [];

  const ids = staff.map((member) => member.id);
  const dayStart = startOfDay(now, timeZone);
  const todayKey = clinicDateKey(now, timeZone);

  const [shifts, leaves] = await Promise.all([
    prisma.shift.findMany({
      where: { staffId: { in: ids }, endsAt: { gte: dayStart } },
      select: { id: true, staffId: true, startsAt: true, endsAt: true },
      orderBy: { startsAt: 'asc' },
      take: 1000,
    }),
    listApprovedLeaveCovering(ids, todayKey),
  ]);

  const statuses = staff.map((member) =>
    buildPublicDoctorStatus({
      staff: member as PublicStaffInput,
      shifts: shifts as ShiftLike[],
      leaves,
      now,
      timeZone,
    }),
  );

  const rank = { ON_DUTY_NOW: 0, ON_LEAVE: 1, NOT_ON_DUTY: 2 } as const;
  return statuses.sort((a, b) => rank[a.status] - rank[b.status] || a.name.localeCompare(b.name));
}

/** Doctors (any) with today's duty status — the dashboard "who is on duty". */
export async function getOnDutyDoctors(now: Date = new Date()): Promise<PublicDoctor[]> {
  const settings = await getSettingsMap();
  return getDoctorDutyStatuses(now, timezoneFrom(settings), false);
}

/**
 * PUBLIC data function (no UI yet): only doctors flagged `showOnWebsite`.
 * Returns a strict, allow-listed projection — never phone, email or internal
 * fields (enforced by `buildPublicDoctorStatus`, which is unit tested).
 */
export async function getPublicDoctorStatus(now: Date = new Date()): Promise<PublicDoctor[]> {
  const settings = await getSettingsMap();
  return getDoctorDutyStatuses(now, timezoneFrom(settings), true);
}

export { dateColumnToKey };
