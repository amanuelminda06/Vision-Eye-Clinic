import { cache } from 'react';
import { prisma } from '@/lib/prisma';
import { DEFAULT_TIMEZONE } from '@/lib/datetime';
import { getSettingsMap, settingsFromMap, timezoneFrom } from '@/modules/settings';
import { resolveContent, type SiteContentMap } from './content';
import {
  toPublicFeedback,
  toPublicService,
  type PublicFeedback,
  type PublicFeedbackRow,
  type PublicService,
  type PublicServiceRow,
} from './projection';

/**
 * Public-website data access. Every function here returns ONLY whitelisted
 * fields — no staff phone/email, no patient data, and never a service price.
 *
 * Wrapped in React `cache` so a layout and its page share one query per request.
 */

export const getSiteContent = cache(async (): Promise<SiteContentMap> => {
  const rows = await prisma.siteContent.findMany({
    select: { key: true, valueEn: true, valueAm: true },
  });
  return resolveContent(rows);
});

/** Public service catalogue: flagged + active only. The price is never selected. */
export const getPublicServices = cache(async (): Promise<PublicService[]> => {
  const rows = await prisma.service.findMany({
    where: { isPublic: true, active: true },
    select: {
      id: true,
      name: true,
      nameEn: true,
      nameAm: true,
      category: true,
      displayOrder: true,
      imageUrl: true,
      publicDescriptionEn: true,
      publicDescriptionAm: true,
    },
    orderBy: [{ displayOrder: 'asc' }, { category: 'asc' }, { name: 'asc' }],
  });
  return rows.map((row) => toPublicService(row as PublicServiceRow));
});

/**
 * Feedback entries the OWNER approved for the website (the CMS
 * "show on website" checkbox), newest first. Only the display name, the text
 * and the rating are selected — never the reply address or the ip hash.
 */
export const getPublicFeedbackTestimonials = cache(
  async (): Promise<PublicFeedback[]> => {
    const rows = await prisma.feedback.findMany({
      where: { showOnWebsite: true },
      select: { id: true, name: true, text: true, rating: true },
      orderBy: { createdAt: 'desc' },
      take: 12,
    });
    return rows.map((row) => toPublicFeedback(row as PublicFeedbackRow));
  },
);

export type ClinicProfile = {
  name: string;
  timezone: string;
  workingHours: ReturnType<typeof settingsFromMap>['working_hours'];
  reviewLink: string;
  defaultLanguage: string;
};

/** Non-sensitive clinic profile used for contact/JSON-LD (no prices, no patients). */
export const getClinicProfile = cache(async (): Promise<ClinicProfile> => {
  const map = await getSettingsMap();
  const settings = settingsFromMap(map);
  return {
    name: settings.clinic_name,
    timezone: timezoneFrom(map) || DEFAULT_TIMEZONE,
    workingHours: settings.working_hours,
    reviewLink: settings.review_link,
    defaultLanguage: settings.default_language,
  };
});

export type { PublicService };
