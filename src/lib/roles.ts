import type { Role } from '@prisma/client';

export const ALL_ROLES: Role[] = ['OWNER', 'RECEPTION', 'STAFF'];

export function isRole(value: unknown): value is Role {
  return typeof value === 'string' && (ALL_ROLES as string[]).includes(value);
}

/** Pure role check — the single source of truth for role membership. */
export function hasRole(role: Role | null | undefined, allowed: Role[]): boolean {
  return !!role && allowed.includes(role);
}
