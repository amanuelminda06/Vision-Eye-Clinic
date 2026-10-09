import { toCsv } from './csv';
import { toXlsx } from './xlsx';
import { writeAudit, type AuditActor } from './audit';

export type ExportFormat = 'csv' | 'xlsx';
export type ExportCell = string | number | boolean | null | undefined;

export function isExportFormat(value: unknown): value is ExportFormat {
  return value === 'csv' || value === 'xlsx';
}

/**
 * One export response for either format. CSV stays the RFC-4180 + BOM writer;
 * XLSX is produced by the dependency-free writer in `./xlsx`. Every export is
 * written to the audit trail by the caller (see `auditExport`).
 */
export function exportResponse(
  filename: string,
  format: ExportFormat,
  headers: string[],
  rows: Array<ExportCell[]>,
): Response {
  if (format === 'xlsx') {
    const buffer = toXlsx({ name: filename.replace(/\.csv$/, ''), headers, rows });
    return new Response(new Uint8Array(buffer), {
      headers: {
        'Content-Type':
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Content-Disposition': `attachment; filename="${filename.replace(/\.csv$/, '.xlsx')}"`,
        'Cache-Control': 'no-store',
      },
    });
  }
  const csv = toCsv(headers, rows as Array<Array<string | number | boolean | null | undefined>>);
  return new Response(csv, {
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': `attachment; filename="${filename}"`,
      'Cache-Control': 'no-store',
    },
  });
}

/**
 * Audit seam for exports: records who downloaded what, when, and in which
 * format. Silently no-ops on audit write failures (never blocks the download).
 */
export async function auditExport(
  actor: AuditActor,
  entity: string,
  filters: Record<string, unknown> = {},
  format: ExportFormat = 'csv',
): Promise<void> {
  await writeAudit({
    user: actor,
    entity,
    entityId: 'export',
    action: 'EXPORTED',
    new: { ...filters, format },
  });
}