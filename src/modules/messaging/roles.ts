import type { Role } from '@prisma/client';

/** Viewing and sending prepared messages: front-desk roles. */
export const MESSAGE_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/** Template editing, campaign creation (unless enabled), reports, jobs. */
export const MESSAGE_OWNER_ROLES: Role[] = ['OWNER'];

export function canEditTemplates(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** Curating the Message Library (archive/edit/hide) is owner-only; everyone may reuse. */
export function canManageLibrary(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** Saved audiences ("Audience" list) are owner-managed. */
export function canManageAudiences(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** The group-delivery override setting is owner-only. */
export function canOverrideGroupFilter(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

export function canSendMessages(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

/**
 * Campaigns are OWNER-only unless the `reception_can_send_campaigns` setting is
 * true, in which case reception may also create them.
 */
export function canSendCampaigns(
  role: Role | null | undefined,
  receptionAllowed: boolean,
): boolean {
  if (role === 'OWNER') return true;
  return role === 'RECEPTION' && receptionAllowed;
}
