import type { Prisma } from '@prisma/client';
import type Decimal from 'decimal.js';
import { prisma } from '@/lib/prisma';
import { toDecimalString } from '@/lib/money';
import { isServiceCategory } from './categories';

export type ServiceListFilters = {
  q?: string;
  category?: string;
  status?: 'true' | 'false' | '';
};

export function buildServiceWhere(filters: ServiceListFilters): Prisma.ServiceWhereInput {
  const and: Prisma.ServiceWhereInput[] = [];
  const q = filters.q?.trim();
  if (q) and.push({ name: { contains: q, mode: 'insensitive' } });
  if (filters.category && isServiceCategory(filters.category)) {
    and.push({ category: filters.category });
  }
  if (filters.status === 'true') and.push({ active: true });
  if (filters.status === 'false') and.push({ active: false });
  return and.length ? { AND: and } : {};
}

export type ServiceRow = {
  id: string;
  name: string;
  nameAm: string | null;
  category: string;
  /** Decimal serialized as a string (safe across the RSC boundary). */
  currentPrice: string;
  active: boolean;
  isPublic: boolean;
  publicDescriptionEn: string | null;
  publicDescriptionAm: string | null;
  createdAt: Date;
  updatedAt: Date;
};

type ServiceDbRow = {
  id: string;
  name: string;
  nameAm: string | null;
  category: string;
  currentPrice: Decimal;
  active: boolean;
  isPublic: boolean;
  publicDescriptionEn: string | null;
  publicDescriptionAm: string | null;
  createdAt: Date;
  updatedAt: Date;
};

function serialize(row: ServiceDbRow): ServiceRow {
  return {
    id: row.id,
    name: row.name,
    nameAm: row.nameAm,
    category: row.category,
    currentPrice: toDecimalString(row.currentPrice),
    active: row.active,
    isPublic: row.isPublic,
    publicDescriptionEn: row.publicDescriptionEn,
    publicDescriptionAm: row.publicDescriptionAm,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  };
}

const SELECT = {
  id: true,
  name: true,
  nameAm: true,
  category: true,
  currentPrice: true,
  active: true,
  isPublic: true,
  publicDescriptionEn: true,
  publicDescriptionAm: true,
  createdAt: true,
  updatedAt: true,
} as const;

export async function listServices(filters: ServiceListFilters): Promise<ServiceRow[]> {
  const rows = await prisma.service.findMany({
    where: buildServiceWhere(filters),
    select: SELECT,
    orderBy: [{ active: 'desc' }, { name: 'asc' }],
  });
  return rows.map(serialize);
}

/** Active services only — used by the new-sale screen (prices shown there). */
export async function listActiveServices(): Promise<ServiceRow[]> {
  const rows = await prisma.service.findMany({
    where: { active: true },
    select: SELECT,
    orderBy: [{ category: 'asc' }, { name: 'asc' }],
  });
  return rows.map(serialize);
}

export async function getService(id: string): Promise<ServiceRow | null> {
  const row = await prisma.service.findUnique({ where: { id }, select: SELECT });
  return row ? serialize(row) : null;
}

export type PriceHistoryRow = {
  id: string;
  oldPrice: string;
  newPrice: string;
  changedBy: string;
  changedByName: string | null;
  changedAt: Date;
};

export async function listPriceHistory(serviceId: string): Promise<PriceHistoryRow[]> {
  const rows = await prisma.servicePriceHistory.findMany({
    where: { serviceId },
    orderBy: { changedAt: 'desc' },
    take: 100,
  });

  const userIds = [...new Set(rows.map((row) => row.changedBy))];
  const users = userIds.length
    ? await prisma.user.findMany({
        where: { id: { in: userIds } },
        select: { id: true, name: true },
      })
    : [];
  const nameById = new Map(users.map((user) => [user.id, user.name]));

  return rows.map((row) => ({
    id: row.id,
    oldPrice: toDecimalString(row.oldPrice),
    newPrice: toDecimalString(row.newPrice),
    changedBy: row.changedBy,
    changedByName: nameById.get(row.changedBy) ?? null,
    changedAt: row.changedAt,
  }));
}

/** Minimal option list for filters (id + name). */
export async function listServiceOptions(): Promise<Array<{ id: string; name: string }>> {
  return prisma.service.findMany({
    select: { id: true, name: true },
    orderBy: { name: 'asc' },
  });
}
