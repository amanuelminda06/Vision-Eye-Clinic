import { z } from 'zod';
import {
  DATE_RE,
  MAX_REQUEST_INTEREST,
  MAX_REQUEST_MESSAGE,
  MAX_REQUEST_NAME,
  TIME_WINDOWS,
} from '@/modules/appointments/constants';

/** Time-window keys offered by the public booking form. */
export const BOOKING_WINDOWS = TIME_WINDOWS.map((window) => window.key) as [string, ...string[]];

/**
 * Public booking form. `website` is a honeypot: humans never see it, bots fill
 * it. `isHoneypotTripped` lets the route quietly accept-and-drop such posts.
 */
export const bookingFormSchema = z.object({
  name: z.string().trim().min(1, 'required').max(MAX_REQUEST_NAME, 'tooLong'),
  phone: z.string().trim().min(1, 'required').max(32, 'tooLong'),
  preferredDate: z.string().regex(DATE_RE, 'invalidDate'),
  preferredTimeWindow: z.enum(BOOKING_WINDOWS, 'invalidTimeWindow').default('any'),
  serviceInterest: z
    .string()
    .trim()
    .max(MAX_REQUEST_INTEREST, 'tooLong')
    .optional()
    .transform((value) => value || null),
  message: z
    .string()
    .trim()
    .max(MAX_REQUEST_MESSAGE, 'tooLong')
    .optional()
    .transform((value) => value || null),
  language: z.enum(['en', 'am'], 'invalidLanguage').default('en'),
  consent: z
    .union([z.boolean(), z.enum(['true', 'false'])])
    .transform((value) => value === true || value === 'true')
    .refine((value) => value === true, 'consentRequired'),
  /** Honeypot — must stay empty. */
  website: z.string().max(0, 'spam').optional().default(''),
  turnstileToken: z.string().max(4096, 'tooLong').optional(),
});

export type BookingFormValues = z.infer<typeof bookingFormSchema>;

export function isHoneypotTripped(values: Pick<BookingFormValues, 'website'>): boolean {
  return values.website.trim().length > 0;
}

/** Content editor: flat `<key>_en` / `<key>_am` fields, keys validated by the action. */
export const contentEditSchema = z.record(z.string(), z.string().max(5000, 'tooLong'));
