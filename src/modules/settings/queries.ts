import { prisma } from '@/lib/prisma';
import { DEFAULT_TIMEZONE } from '@/lib/datetime';
import { WEEKDAYS, type SettingsFormValues } from './schemas';

/** All seeded settings as a plain key → value map. */
export async function getSettingsMap(): Promise<Record<string, unknown>> {
  const rows = await prisma.setting.findMany({ select: { key: true, value: true } });
  return Object.fromEntries(rows.map((row) => [row.key, row.value]));
}

/** Clinic timezone from settings, falling back to the default. */
export function timezoneFrom(map: Record<string, unknown>): string {
  const value = map.timezone;
  return typeof value === 'string' && value ? value : DEFAULT_TIMEZONE;
}

/** Matches prisma/seed.ts — used when a setting row is missing. */
export const DEFAULT_WORKING_HOURS: SettingsFormValues['working_hours'] = {
  mon: { open: '08:00', close: '17:00', closed: false },
  tue: { open: '08:00', close: '17:00', closed: false },
  wed: { open: '08:00', close: '17:00', closed: false },
  thu: { open: '08:00', close: '17:00', closed: false },
  fri: { open: '08:00', close: '17:00', closed: false },
  sat: { open: '08:00', close: '12:00', closed: false },
  sun: { open: '08:00', close: '12:00', closed: true },
};

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' && value ? value : fallback;
}

function num(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

function day(value: unknown, fallback: SettingsFormValues['working_hours']['mon']) {
  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>;
    return {
      open: str(record.open, fallback.open),
      close: str(record.close, fallback.close),
      closed: record.closed === true,
    };
  }
  return fallback;
}

/** Builds validated form values from the raw settings map (with defaults). */
export function settingsFromMap(map: Record<string, unknown>): SettingsFormValues {
  const hours = (map.working_hours ?? {}) as Record<string, unknown>;
  const working_hours = Object.fromEntries(
    WEEKDAYS.map((weekDay) => [weekDay, day(hours[weekDay], DEFAULT_WORKING_HOURS[weekDay])]),
  ) as SettingsFormValues['working_hours'];

  return {
    clinic_name: str(map.clinic_name, 'Vision Eye Clinic'),
    currency: str(map.currency, 'ETB'),
    timezone: str(map.timezone, DEFAULT_TIMEZONE),
    working_hours,
    reminder_send_time: str(map.reminder_send_time, '14:00'),
    reminder_send_by_offset_minutes: num(map.reminder_send_by_offset_minutes, 60),
    followup_lead_days: num(map.followup_lead_days, 3),
    recall_months: num(map.recall_months, 12),
    review_link: str(map.review_link, ''),
    sms_mode: map.sms_mode === 'GATEWAY' ? 'GATEWAY' : 'MANUAL_LINK',
    reception_can_send_campaigns: map.reception_can_send_campaigns === true,
    campaign_max_recipients: Math.min(300, Math.max(1, num(map.campaign_max_recipients, 300))),
    digest_enabled: map.digest_enabled === true,
    digest_email: str(map.digest_email, ''),
    default_language: map.default_language === 'am' ? 'am' : 'en',
    group_sms_allow_for_filtered: map.group_sms_allow_for_filtered === true,
    group_sms_max_recipients: Math.min(100, Math.max(1, num(map.group_sms_max_recipients, 15))),
  };
}
