import { z } from 'zod';
import {
  CAMPAIGN_LANGUAGE_MODES,
  CAMPAIGN_DELIVERY_MODES,
  CAMPAIGN_TAG_MODES,
  CAMPAIGN_UPCOMING_MODES,
  LIBRARY_CATEGORIES,
  MESSAGE_LANGUAGES,
  MAX_CAMPAIGN_MESSAGE,
  MAX_CAMPAIGN_TITLE,
  MAX_SKIP_REASON,
  MAX_TEMPLATE_BODY,
  TEMPLATE_KEYS,
} from './constants';
import { RECIPIENT_PRESET_RANGES } from './recipient-filter';
import { SERVICE_CATEGORIES } from '@/modules/services/categories';

/** Server-side validation for the messaging module (i18n message KEYS). */

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const dateKey = z.string().regex(DATE_RE, 'invalidDate');
const dateOrEmpty = z.union([z.literal(''), dateKey]);
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

/** Legacy (pre-6B) filter — still used to normalise old campaigns. */
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

/** The richer Slice-6B recipient filter. */
export const recipientFilterSchema = z.object({
  tags: z.array(z.string().trim().max(40, 'tooLong')).max(20, 'tooMany'),
  tagMode: z.enum(CAMPAIGN_TAG_MODES),
  serviceIds: z.array(z.string().trim().max(64, 'tooLong')).max(50, 'tooMany'),
  serviceCategory: z.union([z.literal(''), z.enum(SERVICE_CATEGORIES)]),
  language: z.union([z.literal(''), z.enum(MESSAGE_LANGUAGES)]),
  lastVisitFrom: dateOrEmpty,
  lastVisitTo: dateOrEmpty,
  boughtServiceFrom: dateOrEmpty,
  boughtServiceTo: dateOrEmpty,
  upcoming: z.enum(CAMPAIGN_UPCOMING_MODES),
  birthdayMonth: z.coerce.number().int().min(0).max(12),
  handPickedIds: z.array(z.string().trim().max(64, 'tooLong')).max(1000, 'tooMany'),
  presetRange: z.enum(RECIPIENT_PRESET_RANGES),
});

export type RecipientFilterValues = z.infer<typeof recipientFilterSchema>;

export const campaignFormSchema = recipientFilterSchema.extend({
  title: z.string().trim().max(MAX_CAMPAIGN_TITLE, 'tooLong').optional(),
  templateKey: z.enum(TEMPLATE_KEYS, 'invalidTemplate'),
  customBody: z.string().trim().max(MAX_CAMPAIGN_MESSAGE, 'tooLong').optional(),
  libraryItemId: z.string().trim().max(64, 'tooLong').optional(),
  languageMode: z.enum(CAMPAIGN_LANGUAGE_MODES),
  fixedLanguage: z.enum(MESSAGE_LANGUAGES),
  deliveryMode: z.enum(CAMPAIGN_DELIVERY_MODES),
  expiresAt: dateOrEmpty,
  acknowledgedGroupWarning: checkbox,
});

export type CampaignFormValues = z.infer<typeof campaignFormSchema>;

export const messageDraftSchema = z.object({
  id: z.string().trim().max(64, 'tooLong').optional(),
  title: z.string().trim().max(MAX_CAMPAIGN_TITLE, 'tooLong').optional(),
  body: z.string().trim().min(1, 'required').max(MAX_CAMPAIGN_MESSAGE, 'tooLong'),
  language: z.enum(MESSAGE_LANGUAGES),
});

export type MessageDraftValues = z.infer<typeof messageDraftSchema>;

export const libraryItemSchema = z.object({
  id: z.string().trim().max(64, 'tooLong').optional(),
  title: z.string().trim().max(MAX_CAMPAIGN_TITLE, 'tooLong').optional(),
  body: z.string().trim().min(1, 'required').max(MAX_CAMPAIGN_MESSAGE, 'tooLong'),
  language: z.enum(MESSAGE_LANGUAGES),
  category: z.enum(LIBRARY_CATEGORIES),
  isFavorite: checkbox.optional(),
});

export type LibraryItemValues = z.infer<typeof libraryItemSchema>;

export const audienceFormSchema = recipientFilterSchema.extend({
  name: z.string().trim().min(1, 'required').max(MAX_CAMPAIGN_TITLE, 'tooLong'),
});

export type AudienceFormValues = z.infer<typeof audienceFormSchema>;
