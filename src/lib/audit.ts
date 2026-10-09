import { headers } from 'next/headers';
import { Prisma } from '@prisma/client';
import { prisma } from './prisma';
import { diffObjects, hasChanges, redact } from './audit-diff';

export type AuditActor = { id: string; name?: string | null } | null | undefined;

export type AuditInput = {
  /** Authenticated actor (null/undefined for anonymous events like failed logins). */
  user?: AuditActor;
  entity: string;
  entityId: string;
  action: string;
  /** Full object before the change (optional). */
  old?: unknown;
  /** Full object after the change (optional). */
  new?: unknown;
  /** Override the captured client IP (rarely needed). */
  ip?: string | null;
};

/** Client IP for audit rows. */
export async function clientIp(): Promise<string | null> {
  try {
    const h = await headers();
    const forwarded = h.get('x-forwarded-for');
    if (forwarded) return forwarded.split(',')[0].trim();
    return h.get('x-real-ip');
  } catch {
    return null;
  }
}

function toJson(value: unknown): unknown {
  return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/**
 * Writes one audit_log row (rule 3 + 4):
 * - old/new are DIFFED — only changed fields are stored
 * - secrets (passwordHash, password, token…) are redacted
 * - never throws: a failed audit write must not roll back the business op
 */
export async function writeAudit(input: AuditInput): Promise<void> {
  try {
    let oldJson: unknown = null;
    let newJson: unknown = null;

    if (input.old !== undefined || input.new !== undefined) {
      const diff = diffObjects(input.old ?? {}, input.new ?? {});
      if (hasChanges(diff)) {
        oldJson = toJson(diff.old);
        newJson = toJson(diff.new);
      }
    }

    await prisma.auditLog.create({
      data: {
        userId: input.user?.id ?? null,
        entity: input.entity,
        entityId: input.entityId,
        action: input.action,
        oldJson: oldJson === null ? Prisma.DbNull : (oldJson as Prisma.InputJsonValue),
        newJson: newJson === null ? Prisma.DbNull : (newJson as Prisma.InputJsonValue),
        ip: input.ip !== undefined ? input.ip : await clientIp(),
      },
    });
  } catch (err) {
    console.error('[audit] failed to write audit log:', err);
  }
}

export { redact };
