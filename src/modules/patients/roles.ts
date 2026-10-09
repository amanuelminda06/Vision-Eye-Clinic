import type { Role } from '@prisma/client';

/** Patients are visible to owners and reception — never to clinical staff. */
export const PATIENT_ROLES: Role[] = ['OWNER', 'RECEPTION'];
