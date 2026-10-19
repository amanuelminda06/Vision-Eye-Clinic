import { z } from 'zod';

export const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

/** Lowercased, trimmed email — shared by auth and user forms. */
export const emailField = z
  .string()
  .trim()
  .toLowerCase()
  .max(254, 'tooLong')
  .regex(EMAIL_RE, 'invalidEmail');

export const changePasswordSchema = z
  .object({
    currentPassword: z.string().min(1, 'required'),
    newPassword: z.string().min(1, 'required'),
    confirmPassword: z.string().min(1, 'required'),
  })
  .superRefine((values, ctx) => {
    if (values.newPassword === values.currentPassword) {
      ctx.addIssue({ code: 'custom', path: ['newPassword'], message: 'passwordSame' });
    }
    if (values.newPassword !== values.confirmPassword) {
      ctx.addIssue({ code: 'custom', path: ['confirmPassword'], message: 'passwordMismatch' });
    }
  });

export type ChangePasswordValues = z.infer<typeof changePasswordSchema>;
