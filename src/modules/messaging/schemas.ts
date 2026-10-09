import { z } from 'zod';
import {
  CAMPAIGN_LANGUAGE_MODES,
  CAMPAIGN_TAG_MODES,
  CAMPAIGN_UPCOMING_MODES,
  MESSAGE_LANGUAGES,
  MAX_CAMPAIGN_MESSAGE,
  MAX_CAMPAIGN_TITLE,
  MAX_SKIP_REASON,
  MAX_TEMPLATE_BODY,
  TEMPLATE_KEYS,
} from './constants';
import { SERVICE_CATEGORIES } from '@/modules/services/categories';

/** Server-side validation for the messaging module (i18n message KEYS). */

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const dateKey = z.string().regex(DATE_RE, 'invalidDate');
const checkbox = z
  .union([z.boolean(), z.enum(['true', 'false'])])
  .transform((value) => value === true || value === 'true');

export const templateSaveSchema = z.object({
  id: z.string().max(64, 'tooLong'),
  key: z.enum(TEMPLATE_KEYS, 'invalidTemplate'),
  language: z.enum(MESSAGE_LANGUAGES, 'invalidLanguage'),
  body: z.string().trim().min(1, 'required').max(MAX_TEMPLATE_BODY, 'tooLong'),
  active: checkbox,
});

export type TemplateSaveValues = z.infer<typeof templateSaveSchema>;

export const taskSendSchema = z.object({
  id: z.string().min(1, 'required').max(64, 'tooLong'),
});

export const taskSkipSchema = z.object({
  id: z.string().min(1, 'required').max(64, 'tooLong'),
  reason: z.string().trim().min(1, 'required').max(MAX_SKIP_REASON, 'tooLong'),
});

export const taskEditSchema = z.object({
  id: z.string().min(1, 'required').max(64, 'tooLong'),
  message: z.string().trim().min(1, 'required').max(MAX_CAMPAIGN_MESSAGE, 'tooLong'),
});

export const campaignFilterSchema = z.object({
  tags: z.array(z.string().trim().max(40, 'tooLong')).max(20, 'tooMany'),
  tagMode: z.enum(CAMPAIGN_TAG_MODES),
  language: z.union([z.literal(''), z.enum(MESSAGE_LANGUAGES)]),
  lastVisitFrom: z.union([z.literal(''), dateKey]),
  lastVisitTo: z.union([z.literal(''), dateKey]),
  upcoming: z.enum(CAMPAIGN_UPCOMING_MODES),
  serviceCategory: z.union([z.literal(''), z.enum(SERVICE_CATEGORIES)]),
  birthdayMonth: z.coerce.number().int().min(0).max(12),
});

export type CampaignFilterValues = z.infer<typeof campaignFilterSchema>;

export const campaignFormSchema = campaignFilterSchema.extend({
  title: z.string().trim().min(1, 'required').max(MAX_CAMPAIGN_TITLE, 'tooLong'),
  templateKey: z.enum(TEMPLATE_KEYS, 'invalidTemplate'),
  customBody: z.string().trim().max(MAX_CAMPAIGN_MESSAGE, 'tooLong').optional(),
  languageMode: z.enum(CAMPAIGN_LANGUAGE_MODES),
  fixedLanguage: z.enum(MESSAGE_LANGUAGES),
  expiresAt: z.union([z.literal(''), dateKey]),
  acknowledgedGroupWarning: checkbox,
});

export type CampaignFormValues = z.infer<typeof campaignFormSchema>;
