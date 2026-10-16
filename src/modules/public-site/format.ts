import { DEFAULT_TIMEZONE } from '@/lib/datetime';
import type { Locale } from '@/i18n/config';
import type { SettingsFormValues } from '@/modules/settings';

/** Turns a stored phone into a safe `tel:` link (null when unusable). */
export function telHref(phone: string | null | undefined): string | null {
  if (!phone) return null;
  const cleaned = phone.replace(/[^\d+]/g, '');
  return cleaned.replace(/\D/g, '').length >= 6 ? `tel:${cleaned}` : null;
}

/** WhatsApp click-to-chat link from a raw number or a full URL. */
export function whatsappHref(raw: string | null | undefined): string | null {
  if (!raw) return null;
  if (/^https?:\/\//i.test(raw)) return raw;
  const digits = raw.replace(/\D/g, '');
  return digits ? `https://wa.me/${digits}` : null;
}

/** Telegram link from `@handle`, a handle, or a full URL. */
export function telegramHref(raw: string | null | undefined): string | null {
  if (!raw) return null;
  if (/^https?:\/\//i.test(raw)) return raw;
  const handle = raw.replace(/^@/, '').replace(/[^A-Za-z0-9_]/g, '');
  return handle ? `https://t.me/${handle}` : null;
}

/** Formats the next shift instant (clinic time) as e.g. "Mon 14:30". */
export function formatNextAvailable(
  iso: string,
  timeZone: string,
  locale: Locale | string,
): string {
  try {
    return new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en-GB', {
      weekday: 'short',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timeZone || DEFAULT_TIMEZONE,
    }).format(new Date(iso));
  } catch {
    return '';
  }
}
export type WorkingHours = SettingsFormValues['working_hours'];

/**
 * Structured opening-hours lines for the contact page (from clinic settings,
 * not the editable free text). `label` maps a day key to a translated name.
 */
export function workingHoursLines(
  hours: WorkingHours,
  label: (day: keyof WorkingHours) => string,
  closedLabel: string,
): Array<{ day: string; value: string }> {
  const order: Array<keyof WorkingHours> = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  return order.map((day) => {
    const value = hours[day];
    return {
      day: label(day),
      value: value.closed ? closedLabel : `${value.open}–${value.close}`,
    };
  });
}

const SERVICE_IMAGE_URL_PREFIX = '/api/uploads/';

/**
 * Resolves the public-facing image URL for a service. Site-relative paths
 * (e.g. `/services/foo.png`) are returned as-is so the browser/Next Image can
 * load them directly. Uploaded images stored behind `/api/uploads/…` are
 * rewritten to the public allow-listed route so they render on the public
 * website without requiring a session.
 */
export function publicServiceImageUrl(
  serviceId: string,
  imageUrl: string | null | undefined,
): string | null {
  if (!imageUrl) return null;
  if (imageUrl.startsWith(SERVICE_IMAGE_URL_PREFIX)) {
    return `/api/public/services/${serviceId}/image`;
  }
  return imageUrl;
}
