import { verify } from '@node-rs/argon2';
import type { Role } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { clientIp, writeAudit } from '@/lib/audit';
import { isLocked, nextLoginStateAfterFailure, stateAfterLoginSuccess } from '@/lib/login-policy';
import { checkRateLimit } from '@/lib/ratelimit';

/** Valid argon2 hash of a throwaway string — equalizes timing when no user matches. */
const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHRzYWx0$c29tZWhhc2hlZGhhc2h2YWx1ZQ';

export type AuthenticatedUser = {
  id: string;
  email: string;
  name: string;
  role: Role;
  mustChangePassword: boolean;
};

/**
 * Credentials check for Auth.js (email + password, argon2).
 * - rate-limits by IP before touching the database
 * - locks the account for 15 minutes after 5 failed attempts
 * - audits every failure and success
 * Returns the user on success, null otherwise (never reveals which check failed).
 */
export async function verifyCredentials(credentials: unknown): Promise<AuthenticatedUser | null> {
  const raw = credentials as { email?: unknown; password?: unknown } | null | undefined;
  const email = String(raw?.email ?? '')
    .trim()
    .toLowerCase();
  const password = String(raw?.password ?? '');
  if (!email || !password) return null;

  const ip = await clientIp();
  if (!checkRateLimit(`login:${ip ?? 'unknown'}`)) return null;

  const user = await prisma.user.findUnique({ where: { email } });
  const passwordOk = await verify(user?.passwordHash ?? DUMMY_HASH, password);
  const now = new Date();

  if (!user || !passwordOk) {
    if (user) {
      const alreadyLocked = isLocked(user.lockedUntil, now);
      if (!alreadyLocked) {
        const state = nextLoginStateAfterFailure(user.failedLoginCount, now);
        await prisma.user.update({ where: { id: user.id }, data: state });
      }
    }
    await writeAudit({
      user: user ? { id: user.id, name: user.name } : null,
      entity: 'User',
      entityId: user?.id ?? `email:${email}`,
      action: 'LOGIN_FAILED',
      new: { reason: user ? 'invalidPassword' : 'unknownEmail' },
      ip,
    });
    return null;
  }

  if (isLocked(user.lockedUntil, now)) {
    await writeAudit({
      user: { id: user.id, name: user.name },
      entity: 'User',
      entityId: user.id,
      action: 'LOGIN_FAILED',
      new: { reason: 'locked' },
      ip,
    });
    return null;
  }

  if (!user.active) {
    await writeAudit({
      user: { id: user.id, name: user.name },
      entity: 'User',
      entityId: user.id,
      action: 'LOGIN_FAILED',
      new: { reason: 'inactive' },
      ip,
    });
    return null;
  }

  await prisma.user.update({ where: { id: user.id }, data: stateAfterLoginSuccess(now) });
  await writeAudit({
    user: { id: user.id, name: user.name },
    entity: 'User',
    entityId: user.id,
    action: 'LOGIN_SUCCESS',
    ip,
  });

  return {
    id: user.id,
    email: user.email,
    name: user.name,
    role: user.role,
    mustChangePassword: user.mustChangePassword,
  };
}
