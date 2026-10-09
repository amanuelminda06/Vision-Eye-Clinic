/**
 * Client data export: dumps all patients and all sales (line-level) to CSV.
 * Runs with the app's own DB access — no PostgreSQL client tools required.
 *
 *   npm run export:client -- [output-dir]
 *
 * Intended as a portable, human-readable copy of the clinic's data (e.g. when
 * handing records back to the client). Does NOT include clinical notes.
 */
import { PrismaClient } from '@prisma/client';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const prisma = new PrismaClient();

function cell(value: string | number | boolean | null | undefined): string {
  if (value === null || value === undefined) return '';
  const str = String(value);
  return /[",\n\r]/.test(str) ? `"${str.replace(/"/g, '""')}"` : str;
}

function toCsv(headers: string[], rows: Array<Array<string | number | boolean | null | undefined>>) {
  const lines = [headers.map(cell).join(',')];
  for (const row of rows) lines.push(row.map(cell).join(','));
  return `\uFEFF${lines.join('\r\n')}\r\n`;
}

async function main() {
  const outDir = process.argv[2] ?? `exports/${new Date().toISOString().slice(0, 10)}`;
  mkdirSync(outDir, { recursive: true });

  const patients = await prisma.patient.findMany({
    orderBy: { createdAt: 'asc' },
    select: {
      id: true,
      fullName: true,
      phoneE164: true,
      sex: true,
      birthDate: true,
      preferredLanguage: true,
      consentSms: true,
      doNotMessage: true,
      active: true,
      createdAt: true,
    },
  });
  const patientsCsv = toCsv(
    ['id', 'fullName', 'phone', 'sex', 'birthDate', 'language', 'consentSms', 'doNotMessage', 'active', 'createdAt'],
    patients.map((p) => [
      p.id,
      p.fullName,
      p.phoneE164,
      p.sex ?? '',
      p.birthDate ? p.birthDate.toISOString().slice(0, 10) : '',
      p.preferredLanguage,
      p.consentSms,
      p.doNotMessage,
      p.active,
      p.createdAt.toISOString(),
    ]),
  );
  writeFileSync(join(outDir, 'patients.csv'), patientsCsv);

  const sales = await prisma.sale.findMany({
    orderBy: { saleDate: 'asc' },
    select: {
      receiptNumber: true,
      saleDate: true,
      status: true,
      paymentMethod: true,
      total: true,
      patient: { select: { fullName: true, phoneE164: true } },
      doctor: { select: { fullName: true } },
      lines: {
        select: {
          serviceNameSnapshot: true,
          qty: true,
          unitPrice: true,
          lineTotal: true,
        },
      },
    },
  });
  const salesRows: Array<Array<string | number>> = [];
  for (const sale of sales) {
    if (sale.lines.length === 0) {
      salesRows.push([
        sale.receiptNumber,
        sale.saleDate.toISOString(),
        sale.patient?.fullName ?? '',
        sale.patient?.phoneE164 ?? '',
        sale.doctor?.fullName ?? '',
        sale.status,
        sale.paymentMethod,
        sale.total.toString(),
        '',
        '',
        '',
        '',
      ]);
      continue;
    }
    for (const line of sale.lines) {
      salesRows.push([
        sale.receiptNumber,
        sale.saleDate.toISOString(),
        sale.patient?.fullName ?? '',
        sale.patient?.phoneE164 ?? '',
        sale.doctor?.fullName ?? '',
        sale.status,
        sale.paymentMethod,
        sale.total.toString(),
        line.serviceNameSnapshot,
        line.qty,
        line.unitPrice.toString(),
        line.lineTotal.toString(),
      ]);
    }
  }
  const salesCsv = toCsv(
    [
      'receiptNumber',
      'saleDate',
      'patient',
      'patientPhone',
      'doctor',
      'status',
      'paymentMethod',
      'saleTotal',
      'service',
      'qty',
      'unitPrice',
      'lineTotal',
    ],
    salesRows,
  );
  writeFileSync(join(outDir, 'sales.csv'), salesCsv);

  console.log(`✓ exported ${patients.length} patients and ${sales.length} sales to ${outDir}/`);
}

main()
  .catch((err) => {
    console.error(err);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());