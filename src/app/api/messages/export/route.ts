import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { MESSAGE_ROLES } from '@/modules/messaging/roles';
import { listOutbox } from '@/modules/messaging/queries';

/** Today's prepared-message log (OWNER + RECEPTION), exported from the outbox. */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(MESSAGE_ROLES);
  } catch (err) {
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') {
        return Response.redirect(new URL('/change-password', request.url));
      }
      return new Response('Forbidden', { status: 403 });
    }
    throw err;
  }

  const formatRaw = request.nextUrl.searchParams.get('format');
  const format = isExportFormat(formatRaw) ? formatRaw : 'csv';

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const groups = await listOutbox();

  type OutboxExportRow = {
    group: string;
    dueAt: Date;
    handledAt: Date | null;
    status: string;
    patientName: string;
    patientPhoneE164: string;
    templateKey: string;
    sourceType: string;
    language: string;
    renderedMessage: string;
  };
  const rows: OutboxExportRow[] = [];
  const bucketNames = ['dueNow', 'laterToday', 'overdue', 'doneToday', 'blocked'] as const;
  for (const bucket of bucketNames) {
    for (const item of groups[bucket]) {
      rows.push({
        group: bucket,
        dueAt: item.dueAt,
        handledAt: item.handledAt,
        status: item.status,
        patientName: item.patientName,
        patientPhoneE164: item.patientPhoneE164,
        templateKey: String(item.templateKey),
        sourceType: item.sourceType,
        language: item.language,
        renderedMessage: item.renderedMessage,
      });
    }
  }
  rows.sort((a, b) => a.dueAt.getTime() - b.dueAt.getTime());

  await auditExport(actor, 'messageTask', { group: 'today' }, format);

  return exportResponse(
    `messages-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    ['group', 'dueAt', 'handledAt', 'status', 'patient', 'patientPhone', 'template', 'sourceType', 'language', 'message'],
    rows.map((row) => [
      row.group,
      formatInTz(row.dueAt, tz),
      row.handledAt ? formatInTz(row.handledAt, tz) : '',
      row.status,
      row.patientName,
      row.patientPhoneE164,
      row.templateKey,
      row.sourceType,
      row.language,
      row.renderedMessage,
    ]),
  );
}