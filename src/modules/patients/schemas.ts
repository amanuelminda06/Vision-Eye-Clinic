import { z } from 'zod';
import { normalizePhone } from '@/lib/phone';

/** Patient create/edit form payload (tags and status live on the detail page). */

export const MAX_ID_PHOTO_BYTES = 1024 * 1024;

const ID_PHOTO_EXTENSIONS: Record<string, string> = {
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/webp': 'webp',
};

/** File extension for an accepted ID photo type, or null when not allowed. */
export function idPhotoExtension(file: File): string | null {
  return ID_PHOTO_EXTENSIONS[file.type] ?? null;
}

export const SEX_VALUES = ['MALE', 'FEMALE', 'OTHER'] as const;
export const LANGUAGE_VALUES = ['en', 'am'] as const;
export const MAX_NOTES_CHARS = 2000;

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

export const patientFormSchema = z.object({
  fullName: z.string().trim().min(1, 'required').max(120, 'tooLong'),
  phone: z
    .string()
    .trim()
    .min(1, 'required')
    .refine((value) => normalizePhone(value) !== null, 'invalidPhone')
    .transform((value) => normalizePhone(value) as string),
  sex: z
    .union([z.literal(''), z.enum(SEX_VALUES, 'invalidSex')], 'invalidSex')
    .transform((value) => (value === '' ? null : value)),
  birthDate: z
    .string()
    .trim()
    .refine(
      (value) =>
        value === '' ||
        (/^\d{4}-\d{2}-\d{2}$/.test(value) &&
          !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) &&
          value <= todayIso()),
      'invalidDate',
    )
    .transform((value) => value || null),
  // '' → filled from the default_language setting at save time.
  preferredLanguage: z
    .union([z.literal(''), z.enum(LANGUAGE_VALUES, 'invalidLanguage')], 'invalidLanguage')
    .transform((value) => (value === '' ? null : value)),
  notesNonClinical: z
    .string()
    .trim()
    .max(MAX_NOTES_CHARS, 'tooLong')
    .transform((value) => value || null),
  idNo: z.string().trim().max(32, 'tooLong').optional(),
  idPhoto: z
    .custom<File>((file) => file instanceof File, 'invalid')
    .refine((file) => idPhotoExtension(file) !== null, 'idPhotoInvalidType')
    .refine((file) => file.size <= MAX_ID_PHOTO_BYTES, 'idPhotoTooLarge')
    .optional(),
});

export type PatientFormValues = z.infer<typeof patientFormSchema>;
