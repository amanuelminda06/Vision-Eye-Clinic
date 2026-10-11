import type { Prisma, Role } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { toDecimalString } from '@/lib/money';
import { startOfDay, endOfDay } from '@/lib/datetime';
import { phoneSearchVariants } from '@/lib/phone';
import { canSeeAllSales } from './roles';
import type { RevenueSaleInput } from './revenue';
import {
  SALES_EXPORT_CAP,
  SALES_PAGE_SIZE,
  type PaymentMethodValue,
  type SaleStatusValue,
} from './constants';

export type SaleListFilters = {
  from?: string;
  to?: string;
  patient?: string;
  serviceId?: string;
  doctorId?: string;
  status?: '' | SaleStatusValue;
  paymentMethod?: '' | PaymentMethodValue;
  page?: number;
  /** RECEPTION is scoped to their own sales. */
  createdBy?: string;
};

/** Shared WHERE builder for list, export and void inbox. */
export function buildSaleWhere(filters: SaleListFilters, tz: string): Prisma.SaleWhereInput {
  const and: Prisma.SaleWhereInput[] = [];

  if (filters.from) and.push({ saleDate: { gte: startOfDay(filters.from, tz) } });
  if (filters.to) and.push({ saleDate: { lt: endOfDay(filters.to, tz) } });

  const patient = filters.patient?.trim();
  if (patient) {
    const or: Prisma.SaleWhereInput[] = [
      { patient: { fullName: { contains: patient, mode: 'insensitive' } } },
    ];
    for (const variant of phoneSearchVariants(patient) ?? []) {
      or.push({ patient: { phoneE164: { contains: variant } } });
    }
    and.push({ OR: or });
  }

  if (filters.serviceId) and.push({ lines: { some: { serviceId: filters.serviceId } } });
  if (filters.doctorId) and.push({ doctorId: filters.doctorId });
  if (filters.status) and.push({ status: filters.status });
  if (filters.paymentMethod) and.push({ paymentMethod: filters.paymentMethod });
  if (filters.createdBy) and.push({ createdBy: filters.createdBy });

  return and.length ? { AND: and } : {};
}

/**
 * RECEPTION may only see their own sales from the current clinic day; the
 * OWNER's filters pass through untouched.
 */
export function scopeToActor(
  filters: SaleListFilters,
  actor: { id: string; role: Role },
  todayKey: string,
): SaleListFilters {
  if (canSeeAllSales(actor.role)) return filters;
  return { ...filters, createdBy: actor.id, from: todayKey, to: todayKey };
}

const LIST_SELECT = {
  id: true,
  receiptNumber: true,
  saleDate: true,
  status: true,
  paymentMethod: true,
  total: true,
  createdBy: true,
  patient: { select: { id: true, fullName: true } },
  doctor: { select: { id: true, fullName: true } },
  _count: { select: { lines: true } },
} satisfies Prisma.SaleSelect;

export type SaleListRow = {
  id: string;
  receiptNumber: string;
  saleDate: Date;
  status: SaleStatusValue;
  paymentMethod: PaymentMethodValue;
  total: string;
  createdBy: string;
  createdByName: string | null;
  patient: { id: string; fullName: string } | null;
  doctor: { id: string; fullName: string } | null;
  lineCount: number;
};

type SaleDbListRow = Prisma.SaleGetPayload<{ select: typeof LIST_SELECT }>;

async function creatorNames(rows: SaleDbListRow[]): Promise<Map<string, string>> {
  const ids = [...new Set(rows.map((row) => row.createdBy))];
  if (ids.length === 0) return new Map();
  const users = await prisma.user.findMany({
    where: { id: { in: ids } },
    select: { id: true, name: true },
  });
  return new Map(users.map((user) => [user.id, user.name]));
}

function serializeListRow(row: SaleDbListRow, names: Map<string, string>): SaleListRow {
  return {
    id: row.id,
    receiptNumber: row.receiptNumber,
    saleDate: row.saleDate,
    status: row.status,
    paymentMethod: row.paymentMethod,
    total: toDecimalString(row.total),
    createdBy: row.createdBy,
    createdByName: names.get(row.createdBy) ?? null,
    patient: row.patient,
    doctor: row.doctor,
    lineCount: row._count.lines,
  };
}

export async function listSales(
  filters: SaleListFilters,
  tz: string,
): Promise<{
  rows: SaleListRow[];
  total: number;
  page: number;
  pageCount: number;
  sumTotal: string;
}> {
  const where = buildSaleWhere(filters, tz);
  const [total, sum] = await Promise.all([
    prisma.sale.count({ where }),
    prisma.sale.aggregate({ where, _sum: { total: true } }),
  ]);
  const pageCount = Math.max(1, Math.ceil(total / SALES_PAGE_SIZE));
  const page = Math.min(Math.max(1, filters.page ?? 1), pageCount);

  const rows = await prisma.sale.findMany({
    where,
    select: LIST_SELECT,
    orderBy: [{ saleDate: 'desc' }, { id: 'desc' }],
    skip: (page - 1) * SALES_PAGE_SIZE,
    take: SALES_PAGE_SIZE,
  });
  const names = await creatorNames(rows);

  return {
    rows: rows.map((row) => serializeListRow(row, names)),
    total,
    page,
    pageCount,
    sumTotal: toDecimalString(sum._sum.total ?? '0'),
  };
}

export async function listSalesForExport(
  filters: SaleListFilters,
  tz: string,
): Promise<SaleListRow[]> {
  const rows = await prisma.sale.findMany({
    where: buildSaleWhere(filters, tz),
    select: LIST_SELECT,
    orderBy: [{ saleDate: 'desc' }, { id: 'desc' }],
    take: SALES_EXPORT_CAP,
  });
  const names = await creatorNames(rows);
  return rows.map((row) => serializeListRow(row, names));
}

const DETAIL_INCLUDE = {
  patient: { select: { id: true, fullName: true, phoneE164: true } },
  doctor: { select: { id: true, fullName: true } },
  lines: {
    orderBy: { id: 'asc' },
    include: {
      service: { select: { id: true, category: true, receiptLabelEn: true, receiptLabelAm: true } },
    },
  },
} satisfies Prisma.SaleInclude;

export type SaleDetail = {
  id: string;
  receiptNumber: string;
  saleDate: Date;
  status: SaleStatusValue;
  paymentMethod: PaymentMethodValue;
  total: string;
  patient: { id: string; fullName: string; phoneE164: string } | null;
  doctor: { id: string; fullName: string } | null;
  lines: Array<{
    id: string;
    serviceId: string;
    serviceNameSnapshot: string;
    category: string;
    receiptLabelEn: string | null;
    receiptLabelAm: string | null;
    qty: number;
    unitPrice: string;
    lineTotal: string;
  }>;
  voidRequestedBy: string | null;
  voidRequestedByName: string | null;
  voidRequestReason: string | null;
  voidedBy: string | null;
  voidedByName: string | null;
  voidReason: string | null;
  voidedAt: Date | null;
  createdBy: string;
  createdByName: string | null;
  confirmedAt: Date | null;
  createdAt: Date;
  updatedAt: Date;
};

export async function getSale(id: string): Promise<SaleDetail | null> {
  const sale = await prisma.sale.findUnique({ where: { id }, include: DETAIL_INCLUDE });
  if (!sale) return null;

  const userIds = [
    ...new Set([sale.createdBy, sale.voidRequestedBy, sale.voidedBy].filter(Boolean) as string[]),
  ];
  const users = userIds.length
    ? await prisma.user.findMany({
        where: { id: { in: userIds } },
        select: { id: true, name: true },
      })
    : [];
  const names = new Map(users.map((user) => [user.id, user.name]));

  return {
    id: sale.id,
    receiptNumber: sale.receiptNumber,
    saleDate: sale.saleDate,
    status: sale.status,
    paymentMethod: sale.paymentMethod,
    total: toDecimalString(sale.total),
    patient: sale.patient,
    doctor: sale.doctor,
    lines: sale.lines.map((line) => ({
      id: line.id,
      serviceId: line.serviceId,
      serviceNameSnapshot: line.serviceNameSnapshot,
      category: line.service.category,
      receiptLabelEn: line.service.receiptLabelEn,
      receiptLabelAm: line.service.receiptLabelAm,
      qty: line.qty,
      unitPrice: toDecimalString(line.unitPrice),
      lineTotal: toDecimalString(line.lineTotal),
    })),
    voidRequestedBy: sale.voidRequestedBy,
    voidRequestedByName: sale.voidRequestedBy ? (names.get(sale.voidRequestedBy) ?? null) : null,
    voidRequestReason: sale.voidRequestReason,
    voidedBy: sale.voidedBy,
    voidedByName: sale.voidedBy ? (names.get(sale.voidedBy) ?? null) : null,
    voidReason: sale.voidReason,
    voidedAt: sale.voidedAt,
    createdBy: sale.createdBy,
    createdByName: names.get(sale.createdBy) ?? null,
    confirmedAt: sale.confirmedAt,
    createdAt: sale.createdAt,
    updatedAt: sale.updatedAt,
  };
}

export type VoidRequestRow = {
  id: string;
  receiptNumber: string;
  saleDate: Date;
  total: string;
  patientName: string | null;
  requestedBy: string | null;
  requestedByName: string | null;
  reason: string | null;
};

export async function listVoidRequests(): Promise<VoidRequestRow[]> {
  const sales = await prisma.sale.findMany({
    where: { status: 'CONFIRMED', voidRequestedBy: { not: null } },
    orderBy: { updatedAt: 'asc' },
    select: {
      id: true,
      receiptNumber: true,
      saleDate: true,
      total: true,
      voidRequestedBy: true,
      voidRequestReason: true,
      patient: { select: { fullName: true } },
    },
  });

  const ids = [...new Set(sales.map((sale) => sale.voidRequestedBy).filter(Boolean) as string[])];
  const users = ids.length
    ? await prisma.user.findMany({ where: { id: { in: ids } }, select: { id: true, name: true } })
    : [];
  const names = new Map(users.map((user) => [user.id, user.name]));

  return sales.map((sale) => ({
    id: sale.id,
    receiptNumber: sale.receiptNumber,
    saleDate: sale.saleDate,
    total: toDecimalString(sale.total),
    patientName: sale.patient?.fullName ?? null,
    requestedBy: sale.voidRequestedBy,
    requestedByName: sale.voidRequestedBy ? (names.get(sale.voidRequestedBy) ?? null) : null,
    reason: sale.voidRequestReason,
  }));
}

/** Patient detail badge: how many sales this patient has. */
export async function countPatientSales(patientId: string): Promise<number> {
  return prisma.sale.count({
    where: { patientId, status: { in: ['CONFIRMED', 'VOIDED'] } },
  });
}

export type PatientSaleRow = {
  id: string;
  receiptNumber: string;
  saleDate: Date;
  status: SaleStatusValue;
  paymentMethod: PaymentMethodValue;
  total: string;
};

/** Patient detail "Sales" tab: this patient's sales (newest first). */
export async function listPatientSales(
  patientId: string,
  limit = 50,
): Promise<{ rows: PatientSaleRow[]; count: number; confirmedTotal: string }> {
  const [rows, count, sum] = await Promise.all([
    prisma.sale.findMany({
      where: { patientId, status: { in: ['CONFIRMED', 'VOIDED'] } },
      orderBy: { saleDate: 'desc' },
      take: limit,
      select: {
        id: true,
        receiptNumber: true,
        saleDate: true,
        status: true,
        paymentMethod: true,
        total: true,
      },
    }),
    prisma.sale.count({ where: { patientId, status: { in: ['CONFIRMED', 'VOIDED'] } } }),
    prisma.sale.aggregate({
      where: { patientId, status: 'CONFIRMED' },
      _sum: { total: true },
    }),
  ]);

  return {
    rows: rows.map((row) => ({
      id: row.id,
      receiptNumber: row.receiptNumber,
      saleDate: row.saleDate,
      status: row.status,
      paymentMethod: row.paymentMethod,
      total: toDecimalString(row.total),
    })),
    count,
    confirmedTotal: toDecimalString(sum._sum.total ?? '0'),
  };
}

/** Active staff options for the doctor picker. */
export async function listDoctorOptions(): Promise<Array<{ id: string; fullName: string }>> {
  return prisma.staff.findMany({
    where: { active: true },
    select: { id: true, fullName: true },
    orderBy: { fullName: 'asc' },
  });
}

/** Audit trail for one sale (newest first). */
export async function listSaleAudit(saleId: string): Promise<
  Array<{
    id: string;
    action: string;
    userId: string | null;
    userName: string | null;
    createdAt: Date;
  }>
> {
  const rows = await prisma.auditLog.findMany({
    where: { entity: 'Sale', entityId: saleId },
    orderBy: { createdAt: 'desc' },
    take: 100,
    select: { id: true, action: true, userId: true, createdAt: true },
  });
  const ids = [...new Set(rows.map((row) => row.userId).filter(Boolean) as string[])];
  const users = ids.length
    ? await prisma.user.findMany({ where: { id: { in: ids } }, select: { id: true, name: true } })
    : [];
  const names = new Map(users.map((user) => [user.id, user.name]));
  return rows.map((row) => ({
    id: row.id,
    action: row.action,
    userId: row.userId,
    userName: row.userId ? (names.get(row.userId) ?? null) : null,
    createdAt: row.createdAt,
  }));
}

/**
 * Sales (CONFIRMED + VOIDED) with line categories for the revenue report. The
 * callers pass UTC instants for the widest range they need (current + previous
 * periods); the report splits by clinic-tz day itself.
 */
export async function listRevenueSales(from: Date, to: Date): Promise<RevenueSaleInput[]> {
  const rows = await prisma.sale.findMany({
    where: { status: { in: ['CONFIRMED', 'VOIDED'] }, saleDate: { gte: from, lt: to } },
    select: {
      id: true,
      saleDate: true,
      status: true,
      total: true,
      paymentMethod: true,
      doctor: { select: { fullName: true } },
      lines: {
        select: {
          serviceNameSnapshot: true,
          qty: true,
          lineTotal: true,
          service: { select: { category: true } },
        },
      },
    },
  });

  return rows.map((sale) => ({
    id: sale.id,
    saleDate: sale.saleDate,
    status: sale.status,
    total: toDecimalString(sale.total),
    paymentMethod: sale.paymentMethod,
    doctorName: sale.doctor?.fullName ?? null,
    lines: sale.lines.map((line) => ({
      serviceName: line.serviceNameSnapshot,
      category: line.service.category,
      qty: line.qty,
      lineTotal: toDecimalString(line.lineTotal),
    })),
  }));
}
