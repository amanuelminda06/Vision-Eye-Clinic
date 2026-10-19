import { z } from 'zod';
import { EMAIL_RE } from '@/modules/auth/schemas';
import {
  DATE_RE,
  MAX_REQUEST_INTEREST,
  MAX_REQUEST_MESSAGE,
  MAX_REQUEST_NAME,
  TIME_WINDOWS,
} from '@/modules/appointments/constants';
import { MAX_FEEDBACK_EMAIL, MAX_FEEDBACK_NAME, MAX_FEEDBACK_TEXT } from '@/modules/feedback/constants';

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

/**
 * Public feedback form (contact page). Like the booking form it carries a
 * honeypot (`website`) and an optional Turnstile token. The rating is optional
 * and arrives as a string from the form (numbers accepted too). Nothing here
 * is published until the OWNER ticks "show on website" in the CMS.
 */
export const feedbackFormSchema = z.object({
  name: z.string().trim().min(1, 'required').max(MAX_FEEDBACK_NAME, 'tooLong'),
  email: z
    .string()
    .trim()
    .toLowerCase()
    .max(MAX_FEEDBACK_EMAIL, 'tooLong')
    .optional()
    .transform((value) => value || null)
    .refine((value) => value === null || EMAIL_RE.test(value), 'invalidEmail'),
  rating: z
    .union([z.number(), z.string().trim()])
    .optional()
    .transform((value) => (value === undefined || value === '' ? null : Number(value)))
    .refine(
      (value) => value === null || (Number.isInteger(value) && value >= 1 && value <= 5),
      'invalidRating',
    ),
  message: z.string().trim().min(1, 'required').max(MAX_FEEDBACK_TEXT, 'tooLong'),
  language: z.enum(['en', 'am'], 'invalidLanguage').default('en'),
  consent: z
    .union([z.boolean(), z.enum(['true', 'false'])])
    .transform((value) => value === true || value === 'true')
    .refine((value) => value === true, 'consentRequired'),
  /** Honeypot — must stay empty. */
  website: z.string().max(0, 'spam').optional().default(''),
  turnstileToken: z.string().max(4096, 'tooLong').optional(),
});

export type FeedbackFormValues = z.infer<typeof feedbackFormSchema>;

/** Content editor: flat `<key>_en` / `<key>_am` fields, keys validated by the action. */
export const contentEditSchema = z.record(z.string(), z.string().max(5000, 'tooLong'));
