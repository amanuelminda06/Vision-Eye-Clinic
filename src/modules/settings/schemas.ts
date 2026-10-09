import { z } from 'zod';

/** Setting key definitions + validation (i18n message KEYS, never UI strings). */

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

export const SETTING_KEYS = [
  'clinic_name',
  'currency',
  'timezone',
  'working_hours',
  'reminder_send_time',
  'reminder_send_by_offset_minutes',
  'followup_lead_days',
  'recall_months',
  'review_link',
  'sms_mode',
  'reception_can_send_campaigns',
  'campaign_max_recipients',
  'digest_enabled',
  'digest_email',
  'default_language',
] as const;

export type SettingKey = (typeof SETTING_KEYS)[number];

export const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

let timezoneSet: Set<string> | null = null;
function isValidTimezone(value: string): boolean {
  if (!timezoneSet) {
    timezoneSet = new Set(Intl.supportedValuesOf('timeZone'));
  }
  return timezoneSet.has(value);
}

function toMinutes(time: string): number {
  return Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
}

const workingDaySchema = z
  .object({
    open: z.string().regex(HHMM, 'invalidTime'),
    close: z.string().regex(HHMM, 'invalidTime'),
    closed: z.boolean(),
  })
  .refine((day) => day.closed || toMinutes(day.close) > toMinutes(day.open), 'endTimeAfterStart');

const positiveInt = z.coerce.number('invalid').int('invalid').min(1, 'positive');

export const settingsFormSchema = z.object({
  clinic_name: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  currency: z.string().trim().min(1, 'required').max(10, 'tooLong'),
  timezone: z.string().refine(isValidTimezone, 'invalidTimezone'),
  working_hours: z.object({
    mon: workingDaySchema,
    tue: workingDaySchema,
    wed: workingDaySchema,
    thu: workingDaySchema,
    fri: workingDaySchema,
    sat: workingDaySchema,
    sun: workingDaySchema,
  }),
  reminder_send_time: z.string().regex(HHMM, 'invalidTime'),
  reminder_send_by_offset_minutes: positiveInt,
  followup_lead_days: positiveInt,
  recall_months: positiveInt,
  review_link: z
    .string()
    .trim()
    .max(500, 'tooLong')
    .refine((v) => v === '' || /^https?:\/\/\S+$/.test(v), 'invalidUrl'),
  sms_mode: z.string().refine((v) => v === 'MANUAL_LINK' || v === 'GATEWAY', 'invalid'),
  reception_can_send_campaigns: z
    .union([z.boolean(), z.enum(['true', 'false'])])
    .transform((v) => v === true || v === 'true'),
  campaign_max_recipients: z.coerce
    .number('invalid')
    .int('invalid')
    .min(1, 'positive')
    .max(300, 'tooHigh'),
  digest_enabled: z
    .union([z.boolean(), z.enum(['true', 'false'])])
    .transform((v) => v === true || v === 'true'),
  digest_email: z
    .string()
    .trim()
    .max(200, 'tooLong')
    .refine((v) => v === '' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v), 'invalidEmail'),
  default_language: z.string().refine((v) => v === 'en' || v === 'am', 'invalid'),
});

export type SettingsFormValues = z.infer<typeof settingsFormSchema>;

/** Converts parsed values into the Json shape stored in Setting.value. */
export function toSettingValues(values: SettingsFormValues): Record<SettingKey, unknown> {
  return {
    clinic_name: values.clinic_name,
    currency: values.currency,
    timezone: values.timezone,
    working_hours: values.working_hours,
    reminder_send_time: values.reminder_send_time,
    reminder_send_by_offset_minutes: values.reminder_send_by_offset_minutes,
    followup_lead_days: values.followup_lead_days,
    recall_months: values.recall_months,
    review_link: values.review_link,
    sms_mode: values.sms_mode,
    reception_can_send_campaigns: values.reception_can_send_campaigns,
    campaign_max_recipients: values.campaign_max_recipients,
    digest_enabled: values.digest_enabled,
    digest_email: values.digest_email,
    default_language: values.default_language,
  };
}
