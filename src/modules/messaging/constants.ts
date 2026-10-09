import type {
  CampaignStatus,
  MessageSourceType,
  MessageStatus,
  MessageTemplateKey,
} from '@prisma/client';

/** Placeholders a template body may contain. Anything else is an error. */
export const TEMPLATE_VARIABLES = [
  'name',
  'date',
  'time',
  'doctor',
  'clinic',
  'review_link',
  'reopen_date',
] as const;
export type TemplateVariable = (typeof TEMPLATE_VARIABLES)[number];

export const MESSAGE_LANGUAGES = ['en', 'am'] as const;
export type MessageLanguage = (typeof MESSAGE_LANGUAGES)[number];

export function isMessageLanguage(value: unknown): value is MessageLanguage {
  return value === 'en' || value === 'am';
}

export const TEMPLATE_KEYS = [
  'APPT_REMINDER',
  'APPT_CONFIRMATION',
  'APPT_SAME_DAY',
  'NO_SHOW',
  'SCHEDULE_CHANGE',
  'FOLLOWUP_DUE',
  'RECALL',
  'GLASSES_READY',
  'THANK_YOU',
  'BIRTHDAY',
  'WELCOME',
  'CLOSURE_NOTICE',
  'CUSTOM',
] as const satisfies readonly MessageTemplateKey[];

/** `t()` key inside the `messages` namespace describing each template. */
export const TEMPLATE_LABEL_KEYS: Record<MessageTemplateKey, string> = {
  APPT_REMINDER: 'tplApptReminder',
  APPT_CONFIRMATION: 'tplApptConfirmation',
  APPT_SAME_DAY: 'tplApptSameDay',
  NO_SHOW: 'tplNoShow',
  SCHEDULE_CHANGE: 'tplScheduleChange',
  FOLLOWUP_DUE: 'tplFollowupDue',
  RECALL: 'tplRecall',
  GLASSES_READY: 'tplGlassesReady',
  THANK_YOU: 'tplThankYou',
  BIRTHDAY: 'tplBirthday',
  WELCOME: 'tplWelcome',
  CLOSURE_NOTICE: 'tplClosureNotice',
  CUSTOM: 'tplCustom',
};

export const MESSAGE_STATUS_LABEL_KEYS: Record<MessageStatus, string> = {
  PENDING: 'statusPending',
  OPENED: 'statusOpened',
  SENT: 'statusSent',
  SKIPPED: 'statusSkipped',
  EXPIRED: 'statusExpired',
  CANCELLED: 'statusCancelled',
  BLOCKED: 'statusBlocked',
};

export const MESSAGE_SOURCE_LABEL_KEYS: Record<MessageSourceType, string> = {
  APPOINTMENT: 'sourceAppointment',
  FOLLOWUP: 'sourceFollowUp',
  BIRTHDAY: 'sourceBirthday',
  RECALL: 'sourceRecall',
  CAMPAIGN: 'sourceCampaign',
  MANUAL: 'sourceManual',
};

export const CAMPAIGN_STATUS_LABEL_KEYS: Record<CampaignStatus, string> = {
  DRAFT: 'campaignDraft',
  ACTIVE: 'campaignActive',
  COMPLETED: 'campaignCompleted',
  CANCELLED: 'campaignCancelled',
};

/** Eligibility reasons — map to `messages.blocked.*` i18n keys. */
export const BLOCKED_REASONS = [
  'inactive',
  'noConsent',
  'doNotMessage',
  'invalidPhone',
  'sourceCancelled',
] as const;
export type BlockedReason = (typeof BLOCKED_REASONS)[number];

/** `t()` key inside the `messages` namespace describing each blocked reason. */
export const BLOCKED_REASON_LABEL_KEYS: Record<BlockedReason, string> = {
  inactive: 'blockedInactive',
  noConsent: 'blockedNoConsent',
  doNotMessage: 'blockedDoNotMessage',
  invalidPhone: 'blockedInvalidPhone',
  sourceCancelled: 'blockedSourceCancelled',
};

export const CAMPAIGN_LANGUAGE_MODES = ['PATIENT', 'FIXED'] as const;
export type CampaignLanguageMode = (typeof CAMPAIGN_LANGUAGE_MODES)[number];

/** Filter-builder fields the campaign form accepts. */
export const CAMPAIGN_TAG_MODES = ['ANY', 'ALL'] as const;
export type CampaignTagMode = (typeof CAMPAIGN_TAG_MODES)[number];

export const CAMPAIGN_UPCOMING_MODES = ['ANY', 'HAS', 'NONE'] as const;
export type CampaignUpcomingMode = (typeof CAMPAIGN_UPCOMING_MODES)[number];

export const MAX_TEMPLATE_BODY = 1000;
export const MAX_SKIP_REASON = 300;
export const MAX_CAMPAIGN_TITLE = 120;
export const MAX_CAMPAIGN_MESSAGE = 1000;

/** Non-clinical reminder shown above the template editor. */
export const TEMPLATE_SAFETY_NOTE_KEY = 'templateSafetyNote';

/** GSM-7 basic + extension character sets (for the SMS length counter). */
export const GSM7_BASIC =
  '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' +
  '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
export const GSM7_EXTENSION = '\f^{}\\[~]|€';
