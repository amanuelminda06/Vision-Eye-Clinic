import type { Role } from '@prisma/client';

/** Seeing the schedule (scoped to self for STAFF) and one's own leave. */
export const HR_VIEW_ROLES: Role[] = ['OWNER', 'RECEPTION', 'STAFF'];

/** The team leave calendar: OWNER + RECEPTION. */
export const HR_TEAM_LEAVE_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/** Creating/editing shifts and templates: OWNER only. */
export const HR_MANAGE_ROLES: Role[] = ['OWNER'];

/** Requesting leave for oneself — everyone; the OWNER may also act for others. */
export const HR_LEAVE_ROLES: Role[] = ['OWNER', 'RECEPTION', 'STAFF'];

/** Deciding leave and cancelling an approved request: OWNER only. */
export const HR_OWNER_ROLES: Role[] = ['OWNER'];

/** Only the OWNER manages the schedule. */
export function canManageSchedule(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** OWNER + RECEPTION see the whole team schedule; STAFF see only themselves. */
export function canViewTeamSchedule(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

/** OWNER + RECEPTION see the team leave calendar; STAFF see only their own. */
export function canViewTeamLeave(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

/** Only the OWNER approves or rejects a leave request. */
export function canDecideLeave(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** Only the OWNER enters leave on behalf of another staff member. */
export function canRequestForOthers(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/**
 * Segregation of duties: nobody may decide their own leave. The action-level
 * check compares the actor's linked staffId with the request's staffId.
 */
export function isSelfApproval(
  actorStaffId: string | null | undefined,
  requestStaffId: string,
): boolean {
  return !!actorStaffId && actorStaffId === requestStaffId;
}

/**
 * Cancellation: the requester may cancel their own still-pending request;
 * once approved (or for anyone else's request) only the OWNER may cancel.
 */
export function canCancelLeave(opts: {
  role: Role | null | undefined;
  isSelf: boolean;
  status: string;
}): boolean {
  if (opts.status === 'CANCELLED' || opts.status === 'REJECTED') return false;
  if (opts.role === 'OWNER') return true;
  return opts.isSelf && opts.status === 'REQUESTED';
}
