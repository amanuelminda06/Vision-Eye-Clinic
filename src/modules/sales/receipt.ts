import type { Prisma } from '@prisma/client';
import { clinicDateKey } from '@/lib/datetime';

/**
 * Gap-free, per-year receipt numbers (`2026-000123`).
 *
 * The counter is claimed with ONE atomic statement:
 *   INSERT … ON CONFLICT ("year") DO UPDATE SET "lastNumber" = … + 1 RETURNING …
 * Postgres takes a row lock for the conflicting row, so concurrent claims
 * serialize and never share a number. Called inside the sale transaction, so a
 * failed sale rolls the counter back too — no gaps.
 */

export const RECEIPT_PAD = 6;

/** `formatReceiptNumber(2026, 123)` → `2026-000123`. */
export function formatReceiptNumber(year: number, value: number): string {
  if (!Number.isInteger(year) || !Number.isInteger(value) || value < 1) {
    throw new Error(`Invalid receipt number: ${year}-${value}`);
  }
  return `${year}-${String(value).padStart(RECEIPT_PAD, '0')}`;
}

/** Calendar year of a sale instant in the clinic timezone. */
export function receiptYear(date: Date, timeZone: string): number {
  return Number(clinicDateKey(date, timeZone).slice(0, 4));
}

/** Claims the next integer for `year` (atomic — safe under concurrency). */
export async function claimReceiptNumber(
  tx: Prisma.TransactionClient,
  year: number,
): Promise<number> {
  const rows = await tx.$queryRaw<Array<{ lastNumber: number }>>`
    INSERT INTO "ReceiptCounter" ("year", "lastNumber")
    VALUES (${year}, 1)
    ON CONFLICT ("year") DO UPDATE SET "lastNumber" = "ReceiptCounter"."lastNumber" + 1
    RETURNING "lastNumber"
  `;
  return rows[0].lastNumber;
}

/** Claims + formats in one call (inside the sale transaction). */
export async function nextReceiptNumber(
  tx: Prisma.TransactionClient,
  date: Date,
  timeZone: string,
): Promise<string> {
  const year = receiptYear(date, timeZone);
  return formatReceiptNumber(year, await claimReceiptNumber(tx, year));
}
