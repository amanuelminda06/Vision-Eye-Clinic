import type { Role } from '@prisma/client';

/** Reading website feedback (and publishing testimonials) is OWNER-only. */
export const FEEDBACK_ROLES: Role[] = ['OWNER'];
