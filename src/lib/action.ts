import type { Role } from '@prisma/client';
import { AuthorizationError, requireRole, type SessionUser } from './require-role';
import { ValidationError } from './errors';

/**
 * Every server action returns this envelope so client components can render
 * friendly, translated messages (error = i18n key under `common.`).
 * `existing` carries a conflict target (e.g. the patient owning a duplicate
 * phone number) so the UI can link to it.
 */
export type ActionResult<T = unknown> =
  | { ok: true; data?: T }
  | {
      ok: false;
      error?: string;
      fields?: import('./errors').FieldErrors;
      existing?: { id: string; fullName: string };
    };

export function ok<T>(data?: T): ActionResult<T> {
  return data === undefined ? { ok: true } : { ok: true, data };
}

export function fail(
  error = 'unknownError',
  fields?: import('./errors').FieldErrors,
  existing?: { id: string; fullName: string },
): ActionResult<never> {
  const result: Extract<ActionResult, { ok: false }> = { ok: false, error };
  if (fields) result.fields = fields;
  if (existing) result.existing = existing;
  return result;
}

/**
 * Wraps a server action with authentication + authorization + validation:
 * - not signed in → rethrows Next's redirect() (never swallowed)
 * - wrong role    → { ok:false, error:'forbidden' }
 * - forced pw change → { ok:false, error:'passwordChangeRequired' }
 * - zod failure   → { ok:false, error:'validationError', fields }
 * - anything else → rethrown (unknown errors surface in server logs)
 */
export async function withAuth<T>(
  roles: Role[],
  handler: (user: SessionUser) => Promise<ActionResult<T>>,
): Promise<ActionResult<T>> {
  try {
    const user = await requireRole(roles);
    return await handler(user);
  } catch (err) {
    if (err instanceof ValidationError) return fail('validationError', err.fields);
    if (err instanceof AuthorizationError) {
      if (err.code === 'PASSWORD_CHANGE_REQUIRED') return fail('passwordChangeRequired');
      return fail('forbidden');
    }
    throw err;
  }
}
