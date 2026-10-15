import { z } from 'zod';
import { normalizePhone } from '@/lib/phone';

/** Staff create/update payload (photo is validated separately in the schema). */

export const MAX_PHOTO_BYTES = 1024 * 1024;

const PHOTO_EXTENSIONS: Record<string, string> = {
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/webp': 'webp',
};

/** File extension for an accepted photo type, or null when not allowed. */
export function photoExtension(file: File): string | null {
  return PHOTO_EXTENSIONS[file.type] ?? null;
}

const phoneField = z
  .string()
  .trim()
  .refine((value) => value === '' || normalizePhone(value) !== null, 'invalidPhone')
  .transform((value) => (value === '' ? null : normalizePhone(value)));

const checkbox = z
  .union([z.boolean(), z.enum(['true', 'false'])])
  .transform((value) => value === true || value === 'true');

export const staffFormSchema = z.object({
  fullName: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  jobTitle: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  isDoctor: checkbox,
  specialty: z
    .string()
    .trim()
    .max(100, 'tooLong')
    .transform((value) => value || null),
  phone: phoneField,
  showOnWebsite: checkbox,
  photo: z
    .custom<File>((file) => file instanceof File, 'invalid')
    .refine((file) => photoExtension(file) !== null, 'photoInvalidType')
    .refine((file) => file.size <= MAX_PHOTO_BYTES, 'photoTooLarge')
    .optional(),
});

export type StaffFormValues = z.infer<typeof staffFormSchema>;
