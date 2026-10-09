import type { Role } from '@prisma/client';

/** Creating and confirming sales: both clinic roles. */
export const SALES_ROLES: Role[] = ['OWNER', 'RECEPTION'];

/** Revenue reporting, void approval and direct voids: OWNER only. */
export const SALES_OWNER_ROLES: Role[] = ['OWNER'];

/** Nothing financial ("Revenue"/"Sales" totals) is visible to STAFF. */
export function canViewRevenue(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** Only the OWNER approves or rejects a pending void request. */
export function canApproveVoid(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** Only the OWNER voids a confirmed sale directly. */
export function canVoidDirectly(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}

/** RECEPTION (and OWNER) may ask the OWNER to void a sale. */
export function canRequestVoid(role: Role | null | undefined): boolean {
  return role === 'OWNER' || role === 'RECEPTION';
}

/** OWNER sees every sale; RECEPTION only their own, today. */
export function canSeeAllSales(role: Role | null | undefined): boolean {
  return role === 'OWNER';
}
