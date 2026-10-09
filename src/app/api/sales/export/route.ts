import type { NextRequest } from 'next/server';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { exportResponse, auditExport, isExportFormat } from '@/lib/export';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  SALES_ROLES,
  isPaymentMethod,
  isSaleStatus,
  listSalesForExport,
  scopeToActor,
  type SaleListFilters,
} from '@/modules/sales';

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

/** Filtered CSV export (OWNER + RECEPTION, scoped like the list screen). */
export async function GET(request: NextRequest) {
  let actor;
  try {
    actor = await requireRole(SALES_ROLES);
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
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const formatRaw = params.get('format');
  const format = isExportFormat(formatRaw) ? formatRaw : 'csv';

  const fromRaw = params.get('from');
  const toRaw = params.get('to');
  const statusRaw = params.get('status');
  const paymentRaw = params.get('paymentMethod');

  const filters: SaleListFilters = {
    from: fromRaw && DATE_RE.test(fromRaw) ? fromRaw : '',
    to: toRaw && DATE_RE.test(toRaw) ? toRaw : '',
    patient: params.get('patient')?.slice(0, 100) ?? '',
    serviceId: params.get('serviceId')?.slice(0, 64) ?? '',
    doctorId: params.get('doctorId')?.slice(0, 64) ?? '',
    status: isSaleStatus(statusRaw) ? statusRaw : '',
    paymentMethod: isPaymentMethod(paymentRaw) ? paymentRaw : '',
  };
  const scoped = scopeToActor(filters, actor, clinicDateKey(new Date(), tz));
  const rows = await listSalesForExport(scoped, tz);

  await auditExport(actor, 'sale', { ...scoped, page: undefined }, format);

  return exportResponse(
    `sales-${new Date().toISOString().slice(0, 10)}.csv`,
    format,
    [
      'receiptNumber',
      'saleDate',
      'patient',
      'doctor',
      'paymentMethod',
      'status',
      'lines',
      'total',
      'createdBy',
    ],
    rows.map((row) => [
      row.receiptNumber,
      row.saleDate.toISOString(),
      row.patient?.fullName ?? '',
      row.doctor?.fullName ?? '',
      row.paymentMethod,
      row.status,
      row.lineCount,
      row.total,
      row.createdByName ?? '',
    ]),
  );
}
