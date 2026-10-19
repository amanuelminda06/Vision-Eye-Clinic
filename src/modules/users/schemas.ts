import { z } from 'zod';
import { emailField } from '@/modules/auth/schemas';

export const ROLE_VALUES = ['OWNER', 'RECEPTION', 'STAFF'] as const;

export const userCreateSchema = z.object({
  name: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  email: emailField,
  role: z.enum(ROLE_VALUES, 'invalid'),
  staffId: z.string().trim().max(64).optional(),
  password: z.string().min(1, 'required'),
});

export const userUpdateSchema = userCreateSchema.omit({ password: true });

/** Owner-chosen password for another user (set/edit, not the temp reset). */
export const userPasswordSetSchema = z
  .object({
    password: z.string().min(1, 'required'),
    confirmPassword: z.string().min(1, 'required'),
  })
  .superRefine((values, ctx) => {
    if (values.password !== values.confirmPassword) {
      ctx.addIssue({ code: 'custom', path: ['confirmPassword'], message: 'passwordMismatch' });
    }
  });

export type UserCreateValues = z.infer<typeof userCreateSchema>;
export type UserUpdateValues = z.infer<typeof userUpdateSchema>;
export type UserPasswordSetValues = z.infer<typeof userPasswordSetSchema>;
