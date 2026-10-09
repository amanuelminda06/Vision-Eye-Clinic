import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { formatInTz } from '@/lib/datetime';
import { listAuditForExport } from '@/modules/audit';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';

/** OWNER-only CSV/XLSX export of the audit trail (behind the session check). */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(['OWNER']);
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
  const filters = {
    userId: params.get('userId')?.slice(0, 64) || undefined,
    entity: params.get('entity')?.slice(0, 64) || undefined,
    action: params.get('action')?.slice(0, 64) || undefined,
    from: params.get('from')?.slice(0, 10) || undefined,
    to: params.get('to')?.slice(0, 10) || undefined,
  };

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const rows = await listAuditForExport(filters, tz);

  await auditExport(actor, 'auditLog', filters, format);

  return exportResponse(
    `audit-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    ['time', 'user', 'email', 'entity', 'entityId', 'action', 'ip', 'old', 'new'],
    rows.map((row) => [
      formatInTz(row.createdAt, tz),
      row.userName ?? '',
      row.userEmail ?? '',
      row.entity,
      row.entityId,
      row.action,
      row.ip ?? '',
      row.oldJson === null ? '' : JSON.stringify(row.oldJson),
      row.newJson === null ? '' : JSON.stringify(row.newJson),
    ]),
  );
}
