import { prisma } from '@/lib/prisma';
import { combineDateAndTime, dateKeyAdd } from '@/lib/datetime';

export const AUDIT_PAGE_SIZE = 25;

const DATE_KEY = /^\d{4}-\d{2}-\d{2}$/;

export type AuditFilters = {
  userId?: string;
  entity?: string;
  action?: string;
  /** Clinic-local calendar day (YYYY-MM-DD), inclusive. */
  from?: string;
  /** Clinic-local calendar day (YYYY-MM-DD), inclusive. */
  to?: string;
  page?: number;
};

export type AuditRow = {
  id: string;
  entity: string;
  entityId: string;
  action: string;
  oldJson: unknown;
  newJson: unknown;
  ip: string | null;
  createdAt: Date;
  userId: string | null;
  userName: string | null;
  userEmail: string | null;
};

function buildWhere(filters: AuditFilters, timezone: string): Record<string, unknown> {
  const and: Record<string, unknown>[] = [];
  if (filters.userId) and.push({ userId: filters.userId });
  if (filters.entity) and.push({ entity: filters.entity });
  if (filters.action) and.push({ action: filters.action });

  if (filters.from && DATE_KEY.test(filters.from)) {
    and.push({ createdAt: { gte: combineDateAndTime(filters.from, '00:00', timezone) } });
  }
  if (filters.to && DATE_KEY.test(filters.to)) {
    and.push({
      createdAt: {
        lt: combineDateAndTime(dateKeyAdd(filters.to, 1), '00:00', timezone),
      },
    });
  }

  return and.length ? { AND: and } : {};
}

async function attachUsers(rows: AuditRow[]): Promise<void> {
  const ids = [...new Set(rows.map((row) => row.userId).filter((id): id is string => !!id))];
  if (!ids.length) return;
  const users = await prisma.user.findMany({
    where: { id: { in: ids } },
    select: { id: true, name: true, email: true },
  });
  const byId = new Map(users.map((user) => [user.id, user]));
  for (const row of rows) {
    const user = row.userId ? byId.get(row.userId) : undefined;
    row.userName = user?.name ?? null;
    row.userEmail = user?.email ?? null;
  }
}

function toRow(row: Awaited<ReturnType<typeof fetchAuditRows>>[number]): AuditRow {
  return {
    id: row.id,
    entity: row.entity,
    entityId: row.entityId,
    action: row.action,
    oldJson: row.oldJson,
    newJson: row.newJson,
    ip: row.ip,
    createdAt: row.createdAt,
    userId: row.userId,
    userName: null,
    userEmail: null,
  };
}

async function fetchAuditRows(where: Record<string, unknown>, take: number, skip = 0) {
  return prisma.auditLog.findMany({
    where,
    orderBy: { createdAt: 'desc' },
    skip,
    take,
  });
}

export async function listAudit(
  filters: AuditFilters,
  timezone: string,
): Promise<{ rows: AuditRow[]; total: number; page: number; pageCount: number }> {
  const where = buildWhere(filters, timezone);
  const total = await prisma.auditLog.count({ where });
  const pageCount = Math.max(1, Math.ceil(total / AUDIT_PAGE_SIZE));
  const page = Math.min(Math.max(1, filters.page ?? 1), pageCount);

  const raw = await fetchAuditRows(where, AUDIT_PAGE_SIZE, (page - 1) * AUDIT_PAGE_SIZE);
  const rows = raw.map(toRow);
  await attachUsers(rows);
  return { rows, total, page, pageCount };
}

/** CSV export: newest first, capped to keep the response sane. */
export async function listAuditForExport(
  filters: AuditFilters,
  timezone: string,
): Promise<AuditRow[]> {
  const where = buildWhere(filters, timezone);
  const raw = await fetchAuditRows(where, 5000);
  const rows = raw.map(toRow);
  await attachUsers(rows);
  return rows;
}

export async function auditFilterOptions(): Promise<{
  users: { id: string; name: string }[];
  entities: string[];
  actions: string[];
}> {
  const [entities, actions, users] = await Promise.all([
    prisma.auditLog.groupBy({ by: ['entity'], orderBy: { entity: 'asc' } }),
    prisma.auditLog.groupBy({ by: ['action'], orderBy: { action: 'asc' } }),
    prisma.user.findMany({ select: { id: true, name: true }, orderBy: { name: 'asc' } }),
  ]);
  return {
    users,
    entities: entities.map((row) => row.entity),
    actions: actions.map((row) => row.action),
  };
}
