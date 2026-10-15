import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { listPatientsForExport, PATIENT_ROLES } from '@/modules/patients';

/** Filtered CSV/XLSX export (OWNER + RECEPTION) — same filters as the list screen. */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(PATIENT_ROLES);
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
  const consentParam = (params.get('consent') as 'true' | 'false' | null) ?? '';
  const dnmParam = (params.get('dnm') as 'true' | 'false' | null) ?? '';
  const statusParam = (params.get('status') as 'true' | 'false' | null) ?? '';
  const filters: import('@/modules/patients').PatientListFilters = {
    q: params.get('q')?.slice(0, 100) || undefined,
    idNo: params.get('idNo')?.slice(0, 32) || undefined,
    tag: params.get('tag')?.slice(0, 64) || undefined,
    consent: consentParam,
    dnm: dnmParam,
    status: statusParam,
    sort: params.get('sort') ?? undefined,
    dir: params.get('dir') ?? undefined,
  };
  const rows = await listPatientsForExport(filters);

  await auditExport(actor, 'patient', filters, format);

  return exportResponse(
    `patients-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    [
      'id',
      'idNo',
      'fullName',
      'phone',
      'sex',
      'birthDate',
      'preferredLanguage',
      'tags',
      'consentSms',
      'doNotMessage',
      'active',
      'createdAt',
    ],
    rows.map((row) => [
      row.id,
      row.idNo ?? '',
      row.fullName,
      row.phoneE164,
      row.sex ?? '',
      row.birthDate ? row.birthDate.toISOString().slice(0, 10) : '',
      row.preferredLanguage,
      row.tags.map((link) => link.tag.name).join('; '),
      row.consentSms,
      row.doNotMessage,
      row.active,
      row.createdAt.toISOString(),
    ]),
  );
}
