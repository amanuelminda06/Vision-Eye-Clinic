import type { SessionUser } from '@/lib/require-role';
import {
  clinicDateKey,
  combineDateAndTime,
  dateKeyAdd,
  startOfDay,
  endOfDay,
} from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  listRevenueSales,
  buildRevenueReport,
  listVoidRequests,
} from '@/modules/sales';
import {
  countAppointmentsOnDay,
  countOpenFollowUpsOverdue,
  getStaffAppointmentsInPeriod,
} from '@/modules/appointments';
import { countDueToday } from '@/modules/messaging/queries';
import {
  countPendingLeave,
  listShiftsInRange,
  getUserStaffId,
  getLeaveBalance,
  getOnDutyDoctors,
  weekStartKey,
  type PublicDoctor,
} from '@/modules/hr';

export type DashboardData = {
  todayAppointments: number;
  overdueFollowUps: number;
  messagesDueToday: number;
  messagesOverdue: number;
  pendingLeave: number;
  pendingVoids: number;
  /** Confirmed revenue today — only the OWNER sees a non-empty value. */
  revenueToday: string;
  /** Confirmed revenue this (Mon-Sun) week — OWNER only. */
  revenueWeek: string;
  doctors: PublicDoctor[];
  staff: {
    appointmentsToday: number;
    shiftsThisWeek: number;
    nextShiftStart: Date | null;
    leaveRemaining: number | null;
  } | null;
};

export async function getDashboardData(user: SessionUser): Promise<DashboardData> {
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const todayKey = clinicDateKey(new Date(), tz);
  const year = Number(todayKey.slice(0, 4));
  const dayStart = combineDateAndTime(todayKey, '00:00', tz);
  const dayEnd = combineDateAndTime(dateKeyAdd(todayKey, 1), '00:00', tz);
  const weekStart = startOfDay(weekStartKey(todayKey), tz);
  const weekEnd = endOfDay(dateKeyAdd(todayKey, 7), tz);

  if (user.role === 'STAFF') {
    const staffId = await getUserStaffId(user.id);
    const [doctors, myAppointments, shifts, balance] = await Promise.all([
      getOnDutyDoctors(),
      staffId ? getStaffAppointmentsInPeriod(staffId, dayStart, dayEnd) : Promise.resolve([]),
      staffId ? listShiftsInRange(dayStart, weekEnd, staffId) : Promise.resolve([]),
      staffId ? getLeaveBalance(staffId, year) : Promise.resolve(null),
    ]);
    return {
      todayAppointments: 0,
      overdueFollowUps: 0,
      messagesDueToday: 0,
      messagesOverdue: 0,
      pendingLeave: 0,
      pendingVoids: 0,
      revenueToday: '',
      revenueWeek: '',
      doctors,
      staff: {
        appointmentsToday: myAppointments.length,
        shiftsThisWeek: shifts.length,
        nextShiftStart: shifts[0]?.startsAt ?? null,
        leaveRemaining: balance?.remaining ?? null,
      },
    };
  }

  const [todayCount, overdueCount, messages, docs, pendingLeave, voids] =
    await Promise.all([
      countAppointmentsOnDay(todayKey),
      countOpenFollowUpsOverdue(todayKey),
      countDueToday(),
      getOnDutyDoctors(),
      countPendingLeave(),
      listVoidRequests(),
    ]);

  let revenueToday = '';
  let revenueWeek = '';
  if (user.role === 'OWNER') {
    const [today, week] = await Promise.all([
      buildRevenueReport(await listRevenueSales(dayStart, dayEnd), tz),
      buildRevenueReport(await listRevenueSales(weekStart, weekEnd), tz),
    ]);
    revenueToday = today.totalRevenue;
    revenueWeek = week.totalRevenue;
  }

  return {
    todayAppointments: todayCount,
    overdueFollowUps: overdueCount,
    messagesDueToday: messages.dueToday,
    messagesOverdue: messages.overdue,
    pendingLeave,
    pendingVoids: voids.length,
    revenueToday,
    revenueWeek,
    doctors: docs,
    staff: null,
  };
}

export type { PublicDoctor };