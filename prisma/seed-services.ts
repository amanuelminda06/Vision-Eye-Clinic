import type { PrismaClient } from '@prisma/client';
import { multiplyMoney, sumLineTotals, toDecimalString } from '../src/lib/money';
import {
  clinicDateKey,
  combineDateAndTime,
  dateKeyAdd,
  DEFAULT_TIMEZONE,
} from '../src/lib/datetime';

/**
 * The clinic's real six services + ~40 demo sales spread over the past 60 days
 * for the revenue dashboard. Deterministic (no Math.random) and idempotent:
 * services are keyed on `nameEn` (found by nameEn/name, never by a unique
 * constraint), clinic-set prices are preserved, the legacy demo services are
 * deactivated (never deleted), and sales are only generated when the table is
 * empty.
 */

export type ServiceSeed = {
  nameEn: string;
  nameAm?: string;
  category: string;
  price: string;
  displayOrder: number;
  receiptLabelEn?: string;
  receiptLabelAm?: string;
};

export const REAL_SERVICES: ServiceSeed[] = [
  {
    nameEn: 'General Eye Care',
    nameAm: 'ጠቅላላ የዓይን ህክምና',
    category: 'consultation',
    price: '300.00',
    displayOrder: 1,
  },
  {
    nameEn: 'Eye Examination for Glasses (Modern Equipment)',
    nameAm: 'በዘመናዊ መሳሪያዎች የመነጽር ምርመራ',
    category: 'diagnostics',
    price: '500.00',
    displayOrder: 2,
  },
  {
    nameEn: 'Glasses Making and Sales',
    nameAm: 'የመነጽር ስራ እና ሽያጭ',
    category: 'optical',
    price: '1500.00',
    displayOrder: 3,
  },
  {
    nameEn: 'Special Eye Exam and Follow-up for Diabetes and Blood Pressure Patients',
    nameAm: 'ለስኳርና ደም ግፊት ህመምተኞች ልዩ የዓይን ምርመራና ክትትል',
    category: 'specialist',
    price: '400.00',
    displayOrder: 4,
    receiptLabelEn: 'Specialist eye check-up',
    receiptLabelAm: 'ልዩ የዓይን ክትትል',
  },
  {
    nameEn: "Children's Eye Exam and Treatment",
    nameAm: 'የህጻናት የዓይን ምርመራና ህክምና',
    category: 'specialist',
    price: '400.00',
    displayOrder: 5,
  },
  {
    nameEn: 'Minor Eye Surgery',
    nameAm: 'መለስተኛ የዓይን ቀዶ ህክምና',
    category: 'surgery',
    price: '5000.00',
    displayOrder: 6,
  },
];

/** The pre-existing demo services that are retired (kept for history). */
export const LEGACY_DEMO_SERVICES = [
  'Eye Consultation',
  'Cataract Consultation',
  'Post-Op Follow-up',
  'Refraction Test',
  'OCT Scan',
  'Visual Field Test',
  'Slit Lamp Examination',
  'Glaucoma Screening',
  'Eye Pressure Check',
  'Prescription Glasses Fitting',
  'Contact Lens Fitting',
  'Minor Eye Procedure',
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

function matches(seed: ServiceSeed, existing: { name: string; nameEn: string }): boolean {
  return existing.nameEn === seed.nameEn || existing.name === seed.nameEn;
}

async function upsertServices(prisma: PrismaClient): Promise<ServiceRow[]> {
  const existing = await prisma.service.findMany({
    select: { id: true, name: true, nameEn: true, currentPrice: true },
  });
  const rows: ServiceRow[] = [];

  for (const seed of REAL_SERVICES) {
    const found = existing.find((service) => matches(seed, service));
    if (found) {
      await prisma.service.update({
        where: { id: found.id },
        data: {
          name: seed.nameEn,
          nameEn: seed.nameEn,
          nameAm: seed.nameAm ?? null,
          category: seed.category,
          active: true,
          isPublic: true,
          displayOrder: seed.displayOrder,
          receiptLabelEn: seed.receiptLabelEn ?? null,
          receiptLabelAm: seed.receiptLabelAm ?? null,
        },
      });
      rows.push({
        id: found.id,
        name: seed.nameEn,
        currentPrice: toDecimalString(found.currentPrice),
      });
      continue;
    }

    const created = await prisma.service.create({
      data: {
        name: seed.nameEn,
        nameEn: seed.nameEn,
        nameAm: seed.nameAm ?? null,
        category: seed.category,
        currentPrice: seed.price,
        active: true,
        isPublic: true,
        displayOrder: seed.displayOrder,
        receiptLabelEn: seed.receiptLabelEn ?? null,
        receiptLabelAm: seed.receiptLabelAm ?? null,
      },
      select: { id: true, name: true, currentPrice: true },
    });
    rows.push({
      id: created.id,
      name: created.name,
      currentPrice: toDecimalString(created.currentPrice),
    });
  }

  await prisma.service.updateMany({
    where: { name: { in: LEGACY_DEMO_SERVICES } },
    data: { active: false, isPublic: false },
  });

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