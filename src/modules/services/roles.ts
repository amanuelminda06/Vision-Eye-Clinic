import type { Role } from '@prisma/client';

/** Service management (prices) is OWNER-only. */
export const SERVICES_ROLES: Role[] = ['OWNER'];
