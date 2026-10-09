import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { listAppointments, APPT_ROLES } from '@/modules/appointments';

/** Filtered appointment export (OWNER + RECEPTION). Same filter params as the board. */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(APPT_ROLES);
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
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const fromRaw = params.get('from')?.slice(0, 10);
  const toRaw = params.get('to')?.slice(0, 10);
  const filters = {
    from: fromRaw && /^\d{4}-\d{2}-\d{2}$/.test(fromRaw) ? fromRaw : undefined,
    to: toRaw && /^\d{4}-\d{2}-\d{2}$/.test(toRaw) ? toRaw : undefined,
  };

  const rows = await listAppointments({
    ...(filters.from ? { from: new Date(`${filters.from}T00:00:00Z`) } : {}),
    ...(filters.to ? { to: new Date(`${filters.to}T00:00:00Z`) } : {}),
    doctorId: params.get('doctorId')?.slice(0, 64) || undefined,
    patientId: params.get('patientId')?.slice(0, 64) || undefined,
    status: params.get('status')?.slice(0, 32) || undefined,
  });

  await auditExport(actor, 'appointment', filters, format);

  return exportResponse(
    `appointments-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    ['id', 'startsAt', 'endsAt', 'patient', 'patientPhone', 'doctor', 'service', 'status', 'createdFrom', 'notes'],
    rows.map((row) => [
      row.id,
      formatInTz(row.startsAt, tz),
      formatInTz(row.endsAt, tz),
      row.patientName,
      row.patientPhoneE164 ?? '',
      row.doctorName,
      row.serviceName ?? '',
      row.status,
      row.createdFrom,
      row.notes ?? '',
    ]),
  );
}