import { prisma } from '@/lib/prisma';

export type StaffListFilters = {
  q?: string;
  status?: 'true' | 'false' | '';
  page?: number;
};

export const STAFF_PAGE_SIZE = 20;

export type StaffListRow = {
  id: string;
  fullName: string;
  jobTitle: string;
  isDoctor: boolean;
  specialty: string | null;
  phone: string | null;
  photoUrl: string | null;
  showOnWebsite: boolean;
  active: boolean;
};

export async function listStaff(filters: StaffListFilters): Promise<{
  rows: StaffListRow[];
  total: number;
  page: number;
  pageCount: number;
}> {
  const and: Record<string, unknown>[] = [];
  const q = filters.q?.trim();
  if (q) {
    and.push({
      OR: [
        { fullName: { contains: q, mode: 'insensitive' } },
        { jobTitle: { contains: q, mode: 'insensitive' } },
        { specialty: { contains: q, mode: 'insensitive' } },
      ],
    });
  }
  if (filters.status === 'true') and.push({ active: true });
  if (filters.status === 'false') and.push({ active: false });
  const where = and.length ? { AND: and } : {};

  const total = await prisma.staff.count({ where });
  const pageCount = Math.max(1, Math.ceil(total / STAFF_PAGE_SIZE));
  const page = Math.min(Math.max(1, filters.page ?? 1), pageCount);

  const rows = await prisma.staff.findMany({
    where,
    orderBy: { fullName: 'asc' },
    skip: (page - 1) * STAFF_PAGE_SIZE,
    take: STAFF_PAGE_SIZE,
  });

  return { rows, total, page, pageCount };
}

export type StaffRecord = StaffListRow;

export async function getStaff(id: string): Promise<StaffRecord | null> {
  return prisma.staff.findUnique({ where: { id } });
}
