import type { Prisma, Sex } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { phoneSearchVariants } from '@/lib/phone';
import { startOfDay, endOfDay } from '@/lib/datetime';
import { isSimilarName } from './duplicates';

export const PATIENTS_PAGE_SIZE = 20;
export const PATIENT_SORTS = ['fullName', 'birthDate', 'createdAt'] as const;
export type PatientSort = (typeof PATIENT_SORTS)[number];
export type SortDir = 'asc' | 'desc';
export const EXPORT_ROW_CAP = 5000;

export type PatientListFilters = {
  q?: string;
  idNo?: string;
  dateFrom?: string;
  dateTo?: string;
  status?: 'true' | 'false' | '';
  sort?: string;
  dir?: string;
  page?: number;
};

export function sortFrom(value: string | undefined): PatientSort {
  return (PATIENT_SORTS as readonly string[]).includes(value ?? '')
    ? (value as PatientSort)
    : 'fullName';
}

export function dirFrom(value: string | undefined): SortDir {
  return value === 'desc' ? 'desc' : 'asc';
}

/** Shared WHERE builder for the list screen, CSV export and export link. */
export function buildPatientWhere(filters: PatientListFilters): Prisma.PatientWhereInput {
  const and: Prisma.PatientWhereInput[] = [];

  const q = filters.q?.trim();
  if (q) {
    const or: Prisma.PatientWhereInput[] = [{ fullName: { contains: q, mode: 'insensitive' } }];
    for (const variant of phoneSearchVariants(q) ?? []) {
      or.push({ phoneE164: { contains: variant } });
    }
    or.push({ idNo: { contains: q, mode: 'insensitive' } });
    and.push({ OR: or });
  }

  const idNo = filters.idNo?.trim();
  if (idNo) and.push({ idNo: { equals: idNo, mode: 'insensitive' } });

  const dateFrom = filters.dateFrom?.trim();
  if (dateFrom) and.push({ createdAt: { gte: startOfDay(dateFrom) } });
  const dateTo = filters.dateTo?.trim();
  if (dateTo) and.push({ createdAt: { lt: endOfDay(dateTo) } });

  if (filters.status === 'true') and.push({ active: true });
  if (filters.status === 'false') and.push({ active: false });

  return and.length ? { AND: and } : {};
}

const LIST_SELECT = {
  id: true,
  fullName: true,
  phoneE164: true,
  sex: true,
  birthDate: true,
  preferredLanguage: true,
  doNotMessage: true,
  active: true,
  createdAt: true,
  idNo: true,
  tags: { include: { tag: true }, orderBy: { tag: { name: 'asc' } } },
} satisfies Prisma.PatientSelect;

export type PatientListRow = Prisma.PatientGetPayload<{ select: typeof LIST_SELECT }>;

export async function listPatients(filters: PatientListFilters): Promise<{
  rows: PatientListRow[];
  total: number;
  page: number;
  pageCount: number;
}> {
  const where = buildPatientWhere(filters);
  const total = await prisma.patient.count({ where });
  const pageCount = Math.max(1, Math.ceil(total / PATIENTS_PAGE_SIZE));
  const page = Math.min(Math.max(1, filters.page ?? 1), pageCount);
  const sort = sortFrom(filters.sort);
  const dir = dirFrom(filters.dir);

  const rows = await prisma.patient.findMany({
    where,
    select: LIST_SELECT,
    orderBy: [{ [sort]: dir }, { id: 'asc' }],
    skip: (page - 1) * PATIENTS_PAGE_SIZE,
    take: PATIENTS_PAGE_SIZE,
  });

  return { rows, total, page, pageCount };
}

/** Filtered export without pagination (capped). */
export async function listPatientsForExport(
  filters: PatientListFilters,
): Promise<PatientListRow[]> {
  return prisma.patient.findMany({
    where: buildPatientWhere(filters),
    select: LIST_SELECT,
    orderBy: [{ [sortFrom(filters.sort)]: dirFrom(filters.dir) }, { id: 'asc' }],
    take: EXPORT_ROW_CAP,
  });
}

export type PatientDetail = Prisma.PatientGetPayload<{
  include: { tags: { include: { tag: true }; orderBy: { tag: { name: 'asc' } } } };
}> & {
  createdByName: string | null;
};

export async function getPatient(id: string): Promise<PatientDetail | null> {
  const patient = await prisma.patient.findUnique({
    where: { id },
    include: { tags: { include: { tag: true }, orderBy: { tag: { name: 'asc' } } } },
  });
  if (!patient) return null;

  const userIds = [...new Set([patient.createdBy].filter(Boolean))] as string[];
  const users = userIds.length
    ? await prisma.user.findMany({
        where: { id: { in: userIds } },
        select: { id: true, name: true },
      })
    : [];
  const nameById = new Map(users.map((user) => [user.id, user.name]));

  return {
    ...patient,
    createdByName: nameById.get(patient.createdBy) ?? null,
  };
}

/** Phone lookup for duplicate blocking. */
export async function findPatientByPhone(
  phoneE164: string,
): Promise<{ id: string; fullName: string } | null> {
  return prisma.patient.findUnique({
    where: { phoneE164 },
    select: { id: true, fullName: true },
  });
}

/**
 * Very similar name + exactly the same birth date (never blocks, only warns).
 * `birthDate` is a calendar date (`YYYY-MM-DD`).
 */
export async function findSimilarPatients(options: {
  fullName: string;
  birthDate: string | null;
  excludeId?: string;
  limit?: number;
}): Promise<Array<{ id: string; fullName: string }>> {
  if (!options.birthDate) return [];
  const candidates = await prisma.patient.findMany({
    where: {
      birthDate: new Date(`${options.birthDate}T00:00:00Z`),
      ...(options.excludeId ? { id: { not: options.excludeId } } : {}),
    },
    select: { id: true, fullName: true },
    take: 100,
  });
  return candidates
    .filter((candidate) => isSimilarName(candidate.fullName, options.fullName))
    .slice(0, options.limit ?? 5);
}

/** All tag names for the editor's datalist (lowercase). */
export async function listAllTags(): Promise<Array<{ id: string; name: string; color: string }>> {
  return prisma.tag.findMany({
    orderBy: { name: 'asc' },
    select: { id: true, name: true, color: true },
  });
}

export type { Sex };
