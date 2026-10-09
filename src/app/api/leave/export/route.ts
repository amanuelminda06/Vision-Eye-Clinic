import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  HR_VIEW_ROLES,
  LEAVE_STATUSES,
  canViewTeamLeave,
  getUserStaffId,
  listLeaveByStatus,
  listLeaveForStaff,
  listTeamLeaveInRange,
} from '@/modules/hr';
import type { LeaveRow } from '@/modules/hr/queries';

/** Leave export: OWNER + RECEPTION get the team history, STAFF only their own. */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(HR_VIEW_ROLES);
  } catch (err) {
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') {
        return Response.redirect(new URL('/change-password', request.url));
      }
      return new Response('Forbidden', { status: 403 });
    }
    throw err;
  }

  const params = request.nextUrl.searchParams;
  const formatRaw = params.get('format');
  const format = isExportFormat(formatRaw) ? formatRaw : 'csv';

  const statusRaw = (params.get('status') ?? '').toUpperCase();
  const status = (LEAVE_STATUSES as readonly string[]).includes(statusRaw) ? statusRaw : '';
  const fromRaw = params.get('from')?.slice(0, 10);
  const toRaw = params.get('to')?.slice(0, 10);
  const hasRange = !!(
    fromRaw && toRaw && /^\d{4}-\d{2}-\d{2}$/.test(fromRaw) && /^\d{4}-\d{2}-\d{2}$/.test(toRaw)
  );

  const team = canViewTeamLeave(actor.role);
  let rows: LeaveRow[];
  if (!team) {
    const staffId = await getUserStaffId(actor.id);
    rows = staffId ? await listLeaveForStaff(staffId) : [];
  } else if (hasRange) {
    rows = await listTeamLeaveInRange(fromRaw!, toRaw!);
  } else {
    rows = (
      await Promise.all(
        LEAVE_STATUSES.map((leaveStatus) =>
          status && status !== leaveStatus
            ? Promise.resolve([] as LeaveRow[])
            : listLeaveByStatus(leaveStatus),
        ),
      )
    ).flat();
  }
  rows.sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime());

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  await auditExport(actor, 'leaveRequest', { status: status || 'all' }, format);

  return exportResponse(
    `leave-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    ['id', 'staff', 'type', 'startDate', 'endDate', 'halfDay', 'reason', 'status', 'decisionNote', 'createdAt'],
    rows.map((row) => [
      row.id,
      row.staffName,
      row.type,
      row.startDate.toISOString().slice(0, 10),
      row.endDate.toISOString().slice(0, 10),
      row.halfDay,
      row.reason ?? '',
      row.status,
      row.decisionNote ?? '',
      formatInTz(row.createdAt, tz),
    ]),
  );
}