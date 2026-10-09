import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { toCsv } from '@/lib/csv';
import { IMPORT_COLUMNS, PATIENT_ROLES } from '@/modules/patients';

/** CSV import template: exact column order + one example row. */
export async function GET(request: NextRequest) {
  try {
    await requireRole(PATIENT_ROLES);
  } catch (err) {
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') {
        return Response.redirect(new URL('/change-password', request.url));
      }
      return new Response('Forbidden', { status: 403 });
    }
    throw err;
  }

  const csv = toCsv(
    [...IMPORT_COLUMNS],
    [['Example Patient', '0912345678', 'MALE', '1990-01-15', 'glasses', 'true', 'en']],
  );

  return new Response(csv, {
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': 'attachment; filename="patients-template.csv"',
      'Cache-Control': 'no-store',
    },
  });
}
