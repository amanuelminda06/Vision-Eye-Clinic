import { redirect } from 'next/navigation';
import type { Role } from '@prisma/client';
import type { Session } from 'next-auth';
import { auth, loginPath } from '@/auth';
import { hasRole } from './roles';

export type SessionUser = {
  id: string;
  email: string;
  name: string;
  role: Role;
  mustChangePassword: boolean;
};

export type AuthErrorCode = 'UNAUTHENTICATED' | 'FORBIDDEN' | 'PASSWORD_CHANGE_REQUIRED';

export class AuthorizationError extends Error {
  readonly code: AuthErrorCode;
  constructor(code: AuthErrorCode) {
    super(code);
    this.name = 'AuthorizationError';
    this.code = code;
  }
}

function toSessionUser(user: NonNullable<Session>['user']): SessionUser {
  return {
    id: user.id,
    email: user.email ?? '',
    name: user.name ?? user.email ?? '',
    role: user.role,
    mustChangePassword: user.mustChangePassword,
  };
}

/** Any authenticated user (used by /change-password — allowed even while forced). */
export async function requireAuth(): Promise<SessionUser> {
  const session = await auth();
  const user = session?.user;
  if (!user?.id) redirect(loginPath);
  return toSessionUser(user);
}

/**
 * THE single authorization helper (rule 2). Every server action and protected
 * page must call this with its allowed roles.
 *
 * - No session        → redirect to the secret login URL.
 * - mustChangePassword → throws PASSWORD_CHANGE_REQUIRED (use requireAuth on
 *                        the change-password page itself).
 * - Wrong role        → throws FORBIDDEN.
 * - `redirect()` errors are never converted — they are not AuthorizationError.
 */
export async function requireRole(allowed: Role[]): Promise<SessionUser> {
  const session = await auth();
  const user = session?.user;
  if (!user?.id) redirect(loginPath);
  if (user.mustChangePassword) throw new AuthorizationError('PASSWORD_CHANGE_REQUIRED');
  if (!hasRole(user.role, allowed)) throw new AuthorizationError('FORBIDDEN');
  return toSessionUser(user);
}

/**
 * Page variant: turns AuthorizationError into friendly redirects —
 * 403 page for wrong role, change-password when a password change is forced.
 */
export async function requirePageRole(allowed: Role[]): Promise<SessionUser> {
  try {
    return await requireRole(allowed);
  } catch (err) {
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') redirect('/change-password');
      redirect('/403');
    }
    throw err;
  }
}
