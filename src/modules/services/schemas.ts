import { z } from 'zod';
import { isMoneyInRange } from '@/lib/money';
import { SERVICE_CATEGORIES } from './categories';

export const MAX_SERVICE_NAME = 120;
export const MAX_PUBLIC_DESCRIPTION = 2000;
export const MAX_IMAGE_URL = 500;

/** Prices are NUMERIC(12,2): up to 10 integer digits and at most 2 decimals. */
const PRICE_RE = /^\d{1,10}(\.\d{1,2})?$/;

export const serviceFormSchema = z.object({
  /** English display name; `name` on the row is kept in sync with this. */
  nameEn: z.string().trim().min(1, 'required').max(MAX_SERVICE_NAME, 'tooLong'),
  nameAm: z.string().trim().max(MAX_SERVICE_NAME, 'tooLong'),
  category: z.enum(SERVICE_CATEGORIES, 'invalidCategory'),
  currentPrice: z
    .string()
    .trim()
    .min(1, 'required')
    .refine((value) => PRICE_RE.test(value) && isMoneyInRange(value), 'invalidPrice'),
  /** Shown on the public website. The price is never published. */
  isPublic: z
    .string()
    .optional()
    .transform((value) => value === 'true'),
  publicDescriptionEn: z
    .string()
    .trim()
    .max(MAX_PUBLIC_DESCRIPTION, 'tooLong')
    .optional()
    .transform((value) => value || null),
  publicDescriptionAm: z
    .string()
    .trim()
    .max(MAX_PUBLIC_DESCRIPTION, 'tooLong')
    .optional()
    .transform((value) => value || null),
  /** Optional short names printed on receipts (per language). */
  receiptLabelEn: z
    .string()
    .trim()
    .max(MAX_SERVICE_NAME, 'tooLong')
    .optional()
    .transform((value) => value || null),
  receiptLabelAm: z
    .string()
    .trim()
    .max(MAX_SERVICE_NAME, 'tooLong')
    .optional()
    .transform((value) => value || null),
  /** Order on the public services page (lower appears first). */
  displayOrder: z
    .string()
    .trim()
    .default('0')
    .transform((value) => {
      const parsed = Number(value);
      return Number.isFinite(parsed) && parsed >= 0 ? Math.floor(parsed) : 0;
    }),
  /** Optional image shown on the public services page (site-relative path). */
  imageUrl: z
    .string()
    .trim()
    .max(MAX_IMAGE_URL, 'tooLong')
    .optional()
    .transform((value) => value || null),
});

export type ServiceFormValues = z.infer<typeof serviceFormSchema>;
