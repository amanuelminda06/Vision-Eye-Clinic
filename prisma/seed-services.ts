import type { PrismaClient } from '@prisma/client';
import { multiplyMoney, sumLineTotals, toDecimalString } from '../src/lib/money';
import {
  clinicDateKey,
  combineDateAndTime,
  dateKeyAdd,
  DEFAULT_TIMEZONE,
} from '../src/lib/datetime';

/**
 * Demo services + ~40 sales spread over the past 60 days for the revenue
 * dashboard. Deterministic (no Math.random) and idempotent: services are
 * upserted by name, sales are only generated when the table is empty.
 */

export type ServiceSeed = {
  name: string;
  nameAm?: string;
  category: string;
  price: string;
};

export const SERVICE_SEED: ServiceSeed[] = [
  { name: 'Eye Consultation', nameAm: 'የአይን ምክር', category: 'consultation', price: '300.00' },
  { name: 'Cataract Consultation', category: 'consultation', price: '500.00' },
  { name: 'Post-Op Follow-up', category: 'consultation', price: '350.00' },
  { name: 'Refraction Test', nameAm: 'ሪፍራክሽን ፈተና', category: 'refraction', price: '400.00' },
  { name: 'OCT Scan', nameAm: 'OCT ስካን', category: 'diagnostics', price: '1500.00' },
  { name: 'Visual Field Test', category: 'diagnostics', price: '900.00' },
  { name: 'Slit Lamp Examination', category: 'diagnostics', price: '500.00' },
  { name: 'Glaucoma Screening', category: 'tests', price: '700.00' },
  { name: 'Eye Pressure Check', category: 'tests', price: '250.00' },
  {
    name: 'Prescription Glasses Fitting',
    nameAm: 'መነጽር ማስተካከል',
    category: 'glasses',
    price: '600.00',
  },
  { name: 'Contact Lens Fitting', category: 'contact_lenses', price: '800.00' },
  { name: 'Minor Eye Procedure', category: 'surgery', price: '2500.00' },
];

const PAYMENTS = ['CASH', 'MOBILE_MONEY', 'BANK', 'CARD', 'OTHER'] as const;

/** Tiny deterministic LCG so seeds are reproducible. */
function makeRandom(seed: number): () => number {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 4294967296;
  };
}

type ServiceRow = { id: string; name: string; currentPrice: string };

async function upsertServices(prisma: PrismaClient): Promise<ServiceRow[]> {
  const existing = await prisma.service.findMany({
    select: { id: true, name: true, currentPrice: true },
  });
  const byName = new Map(existing.map((service) => [service.name, service]));
  const rows: ServiceRow[] = [];

  for (const seed of SERVICE_SEED) {
    const found = byName.get(seed.name);
    if (found) {
      rows.push({
        id: found.id,
        name: found.name,
        currentPrice: toDecimalString(found.currentPrice),
      });
      continue;
    }
    const created = await prisma.service.create({
      data: {
        name: seed.name,
        nameAm: seed.nameAm ?? null,
        category: seed.category,
        currentPrice: seed.price,
        active: true,
      },
      select: { id: true, name: true, currentPrice: true },
    });
    rows.push({
      id: created.id,
      name: created.name,
      currentPrice: toDecimalString(created.currentPrice),
    });
  }
  return rows;
}

export async function seedServicesAndSales(prisma: PrismaClient): Promise<void> {
  const services = await upsertServices(prisma);
  const setting = await prisma.setting.findUnique({ where: { key: 'timezone' } });
  const tz = typeof setting?.value === 'string' && setting.value ? setting.value : DEFAULT_TIMEZONE;

  console.log(`✓ ${services.length} services`);

  const existingSales = await prisma.sale.count();
  if (existingSales > 0) {
    console.log(`✓ sales already seeded (${existingSales}) — skipped`);
    return;
  }

  const patients = await prisma.patient.findMany({ select: { id: true }, take: 20 });
  const doctor = await prisma.staff.findFirst({ where: { isDoctor: true }, select: { id: true } });
  const creator = await prisma.user.findFirst({
    where: { role: { in: ['RECEPTION', 'OWNER'] } },
    orderBy: { createdAt: 'asc' },
    select: { id: true },
  });
  const owner = await prisma.user.findFirst({
    where: { role: 'OWNER' },
    orderBy: { createdAt: 'asc' },
    select: { id: true },
  });

  const random = makeRandom(20261008);
  const todayKey = clinicDateKey(new Date(), tz);

  type Descriptor = { date: Date; year: number };
  const descriptors: Descriptor[] = [];
  for (let i = 0; i < 40; i++) {
    const dayOffset = Math.floor(random() * 60);
    const dateKey = dateKeyAdd(todayKey, -dayOffset);
    const hour = String(8 + Math.floor(random() * 9)).padStart(2, '0');
    const minute = String(Math.floor(random() * 60)).padStart(2, '0');
    const date = combineDateAndTime(dateKey, `${hour}:${minute}`, tz);
    descriptors.push({ date, year: Number(dateKey.slice(0, 4)) });
  }
  descriptors.sort((a, b) => a.date.getTime() - b.date.getTime());

  const counters = new Map<number, number>();
  let created = 0;
  let voided = 0;

  for (let i = 0; i < descriptors.length; i++) {
    const { date, year } = descriptors[i];
    const nextNumber = (counters.get(year) ?? 0) + 1;
    counters.set(year, nextNumber);
    const receiptNumber = `${year}-${String(nextNumber).padStart(6, '0')}`;

    const lineCount = 1 + Math.floor(random() * 3);
    const chosen = new Set<number>();
    while (chosen.size < lineCount) chosen.add(Math.floor(random() * services.length));

    const lines = [...chosen].map((index) => {
      const service = services[index];
      const qty = 1 + Math.floor(random() * 2);
      return {
        serviceId: service.id,
        serviceNameSnapshot: service.name,
        qty,
        unitPrice: toDecimalString(multiplyMoney(service.currentPrice, 1)),
        lineTotal: toDecimalString(multiplyMoney(service.currentPrice, qty)),
      };
    });

    const total = toDecimalString(
      sumLineTotals(lines.map((line) => ({ unitPrice: line.unitPrice, quantity: line.qty }))),
    );

    const isVoided = i % 13 === 0;
    if (isVoided) voided += 1;

    const patientId =
      random() < 0.8 && patients.length > 0
        ? patients[Math.floor(random() * patients.length)].id
        : null;
    const doctorId = random() < 0.6 ? (doctor?.id ?? null) : null;

    await prisma.sale.create({
      data: {
        receiptNumber,
        patientId,
        doctorId,
        saleDate: date,
        status: isVoided ? 'VOIDED' : 'CONFIRMED',
        paymentMethod: PAYMENTS[Math.floor(random() * PAYMENTS.length)],
        total,
        createdBy: creator?.id ?? owner?.id ?? 'seed',
        confirmedAt: date,
        voidedBy: isVoided ? (owner?.id ?? null) : null,
        voidReason: isVoided ? 'Seeded demo void' : null,
        voidedAt: isVoided ? new Date(date.getTime() + 86_400_000) : null,
        lines: { create: lines },
      },
    });
    created += 1;
  }

  for (const [year, lastNumber] of counters) {
    await prisma.receiptCounter.upsert({
      where: { year },
      update: { lastNumber },
      create: { year, lastNumber },
    });
  }

  console.log(`✓ ${created} sales (${voided} voided) over the last 60 days`);
}
