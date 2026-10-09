import type { Role } from '@prisma/client';

/** Viewing/managing appointments + follow-ups: clinic staff only. */
export const APPT_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/** Handling website appointment requests: clinic staff only. */
export const REQUEST_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/** Follow-up administration: clinic staff only. */
export const FOLLOW_UP_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/**
 * Terminal appointment states (COMPLETED / NO_SHOW / CANCELLED) are final for
 * everyone — only the OWNER may move out of them (with an audit entry).
 */
export const APPT_OWNER_ROLES: Role[] = ['OWNER'];

export function canOverrideTerminalTransition(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

export function canHandleRequests(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

export function canManageAppointments(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

export function canManageFollowUps(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}
