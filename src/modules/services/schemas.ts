import { z } from 'zod';
import { isMoneyInRange } from '@/lib/money';
import { SERVICE_CATEGORIES } from './categories';

export const MAX_SERVICE_NAME = 120;
export const MAX_PUBLIC_DESCRIPTION = 2000;

/** Prices are NUMERIC(12,2): up to 10 integer digits and at most 2 decimals. */
const PRICE_RE = /^\d{1,10}(\.\d{1,2})?$/;

export const serviceFormSchema = z.object({
  name: z.string().trim().min(1, 'required').max(MAX_SERVICE_NAME, 'tooLong'),
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
});

export type ServiceFormValues = z.infer<typeof serviceFormSchema>;
