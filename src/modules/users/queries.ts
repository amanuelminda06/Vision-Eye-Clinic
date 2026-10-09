import type { Role } from '@prisma/client';
import { prisma } from '@/lib/prisma';

export type UserListFilters = {
  q?: string;
  role?: Role | '';
  active?: 'true' | 'false' | '';
  page?: number;
};

export const USERS_PAGE_SIZE = 20;

export type UserListRow = {
  id: string;
  name: string;
  email: string;
  role: Role;
  active: boolean;
  mustChangePassword: boolean;
  lastLoginAt: Date | null;
  staffName: string | null;
};

export async function listUsers(filters: UserListFilters): Promise<{
  rows: UserListRow[];
  total: number;
  page: number;
  pageCount: number;
}> {
  const and: Record<string, unknown>[] = [];
  const q = filters.q?.trim();
  if (q) {
    and.push({
      OR: [
        { name: { contains: q, mode: 'insensitive' } },
        { email: { contains: q, mode: 'insensitive' } },
      ],
    });
  }
  if (filters.role) and.push({ role: filters.role });
  if (filters.active === 'true') and.push({ active: true });
  if (filters.active === 'false') and.push({ active: false });
  const where = and.length ? { AND: and } : {};

  const total = await prisma.user.count({ where });
  const pageCount = Math.max(1, Math.ceil(total / USERS_PAGE_SIZE));
  const page = Math.min(Math.max(1, filters.page ?? 1), pageCount);

  const rows = await prisma.user.findMany({
    where,
    orderBy: { createdAt: 'desc' },
    skip: (page - 1) * USERS_PAGE_SIZE,
    take: USERS_PAGE_SIZE,
    select: {
      id: true,
      name: true,
      email: true,
      role: true,
      active: true,
      mustChangePassword: true,
      lastLoginAt: true,
      staff: { select: { fullName: true } },
    },
  });

  return {
    rows: rows.map((row) => ({
      id: row.id,
      name: row.name,
      email: row.email,
      role: row.role,
      active: row.active,
      mustChangePassword: row.mustChangePassword,
      lastLoginAt: row.lastLoginAt,
      staffName: row.staff?.fullName ?? null,
    })),
    total,
    page,
    pageCount,
  };
}

export type UserRecord = {
  id: string;
  name: string;
  email: string;
  role: Role;
  staffId: string | null;
  active: boolean;
  mustChangePassword: boolean;
  lastLoginAt: Date | null;
};

export async function getUser(id: string): Promise<UserRecord | null> {
  const row = await prisma.user.findUnique({
    where: { id },
    select: {
      id: true,
      name: true,
      email: true,
      role: true,
      staffId: true,
      active: true,
      mustChangePassword: true,
      lastLoginAt: true,
    },
  });
  return row;
}

export async function listStaffOptions(): Promise<{ id: string; fullName: string }[]> {
  return prisma.staff.findMany({
    where: { active: true },
    orderBy: { fullName: 'asc' },
    select: { id: true, fullName: true },
  });
}
