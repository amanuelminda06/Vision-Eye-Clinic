/**
 * Bulk load seed for performance testing (NOT for demo content).
 *
 *   npm run db:up && npm run db:deploy && npm run db:seed && npm run db:load
 *
 * Creates deterministic load:
 *   - 5,000 extra patients (phones in the `+2519…` test range)
 *   - 20,000 sales spread over the last 60 days (≈18k confirmed, some
 *     voided/draft) with 1–3 lines each, against the existing services.
 *
 * Idempotent: skips when the load marker patient already exists.
 */
import { PrismaClient, type PaymentMethod, type SaleStatus } from '@prisma/client';
import { randomUUID } from 'node:crypto';

const prisma = new PrismaClient();

const PATIENTS = 5_000;
const SALES = 20_000;
const DAYS = 60;
const CHUNK = 1_000;

const PAYMENT_METHODS: PaymentMethod[] = ['CASH', 'MOBILE_MONEY', 'BANK', 'CARD', 'OTHER'];

/** Deterministic PRNG so repeated loads look the same. */
function mulberry32(seed: number): () => number {
  let a = seed;
  return () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function chunk<T>(items: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
}

async function main() {
  const markerPhone = '+251900000001';
  const existing = await prisma.patient.findUnique({ where: { phoneE164: markerPhone } });
  if (existing) {
    console.log('✓ load seed already applied (marker patient found) — skipping');
    return;
  }

  const [services, doctors, ownerUser] = await Promise.all([
    prisma.service.findMany({ where: { active: true }, select: { id: true, currentPrice: true } }),
    prisma.staff.findMany({ where: { isDoctor: true, active: true }, select: { id: true } }),
    prisma.user.findFirst({
      where: { role: 'OWNER' },
      select: { id: true },
      orderBy: { createdAt: 'asc' },
    }),
  ]);

  if (services.length === 0) {
    throw new Error('No active services found — run `npm run db:seed` first.');
  }
  if (!ownerUser) {
    throw new Error('No OWNER user found — run `npm run db:seed` first.');
  }
  const actorId = ownerUser.id;
  const random = mulberry32(20261009);
  const pick = <T>(list: T[]): T => list[Math.floor(random() * list.length)];

  // ── patients ──────────────────────────────────────────────────────────────
  console.log(`• creating ${PATIENTS.toLocaleString()} patients…`);
  const patientIds: string[] = [];
  for (let i = 0; i < PATIENTS; i++) patientIds.push(randomUUID());
  const patientRows = patientIds.map((id, i) => ({
    id,
    fullName: `Load Patient ${String(i + 1).padStart(5, '0')}`,
    phoneE164: `+2519${String(10_000_000 + i).padStart(8, '0')}`,
    sex: (['MALE', 'FEMALE'] as const)[i % 2],
    preferredLanguage: i % 5 === 0 ? 'am' : 'en',
    active: i % 50 !== 0,
    createdBy: actorId,
    createdAt: new Date(Date.now() - Math.floor(random() * DAYS * 86_400_000)),
  }));
  for (const batch of chunk(patientRows, CHUNK)) {
    await prisma.patient.createMany({ data: batch, skipDuplicates: true });
  }

  // ── receipt numbering ─────────────────────────────────────────────────────
  const year = new Date().getFullYear();
  const prefix = `${year}-`;
  const last = await prisma.sale.findFirst({
    where: { receiptNumber: { startsWith: prefix } },
    orderBy: { receiptNumber: 'desc' },
    select: { receiptNumber: true },
  });
  let receiptNumber = last ? Number(last.receiptNumber.slice(prefix.length)) : 0;

  // ── sales + lines ─────────────────────────────────────────────────────────
  console.log(`• creating ${SALES.toLocaleString()} sales…`);
  const sales: Array<{
    id: string;
    receiptNumber: string;
    patientId: string;
    doctorId: string | null;
    saleDate: Date;
    status: SaleStatus;
    paymentMethod: PaymentMethod;
    total: string;
    createdBy: string;
    confirmedAt: Date | null;
    voidedAt: Date | null;
    voidReason: string | null;
  }> = [];
  const lines: Array<{
    id: string;
    saleId: string;
    serviceId: string;
    serviceNameSnapshot: string;
    qty: number;
    unitPrice: string;
    lineTotal: string;
  }> = [];

  for (let i = 0; i < SALES; i++) {
    receiptNumber += 1;
    const id = randomUUID();
    const saleDate = new Date(
      Date.now() - Math.floor(random() * DAYS * 86_400_000) - Math.floor(random() * 86_400_000),
    );
    const roll = random();
    const status: SaleStatus = roll < 0.9 ? 'CONFIRMED' : roll < 0.96 ? 'VOIDED' : 'DRAFT';
    const lineCount = 1 + Math.floor(random() * 3);
    let total = 0;
    for (let l = 0; l < lineCount; l++) {
      const service = pick(services);
      const qty = 1 + Math.floor(random() * 2);
      const unit = Number(service.currentPrice);
      const lineTotal = unit * qty;
      total += lineTotal;
      lines.push({
        id: randomUUID(),
        saleId: id,
        serviceId: service.id,
        serviceNameSnapshot: `Service ${service.id.slice(-4)}`,
        qty,
        unitPrice: unit.toFixed(2),
        lineTotal: lineTotal.toFixed(2),
      });
    }
    sales.push({
      id,
      receiptNumber: `${year}-${String(receiptNumber).padStart(6, '0')}`,
      patientId: patientIds[Math.floor(random() * patientIds.length)],
      doctorId: doctors.length && random() < 0.8 ? pick(doctors).id : null,
      saleDate,
      status,
      paymentMethod: pick(PAYMENT_METHODS),
      total: total.toFixed(2),
      createdBy: actorId,
      confirmedAt: status === 'CONFIRMED' ? saleDate : null,
      voidedAt: status === 'VOIDED' ? saleDate : null,
      voidReason: status === 'VOIDED' ? 'Load-test void' : null,
    });
  }

  for (const batch of chunk(sales, CHUNK)) {
    await prisma.sale.createMany({ data: batch, skipDuplicates: true });
  }
  for (const batch of chunk(lines, CHUNK * 2)) {
    await prisma.saleLine.createMany({ data: batch, skipDuplicates: true });
  }

  await prisma.receiptCounter.upsert({
    where: { year },
    create: { year, lastNumber: receiptNumber },
    update: { lastNumber: receiptNumber },
  });

  console.log(
    `✓ load seed done: ${PATIENTS.toLocaleString()} patients, ${SALES.toLocaleString()} sales, ${lines.length.toLocaleString()} lines`,
  );
}

main()
  .catch((err) => {
    console.error(err);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());