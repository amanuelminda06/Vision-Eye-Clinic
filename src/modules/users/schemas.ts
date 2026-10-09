import { z } from 'zod';
import { emailField } from '@/modules/auth';

export const ROLE_VALUES = ['OWNER', 'RECEPTION', 'STAFF'] as const;

export const userCreateSchema = z.object({
  name: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  email: emailField,
  role: z.enum(ROLE_VALUES, 'invalid'),
  staffId: z.string().trim().max(64).optional(),
  password: z.string().min(1, 'required'),
});

export const userUpdateSchema = userCreateSchema.omit({ password: true });

export type UserCreateValues = z.infer<typeof userCreateSchema>;
export type UserUpdateValues = z.infer<typeof userUpdateSchema>;
