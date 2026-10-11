import { prisma } from '@/lib/prisma';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { loadCampaignCandidates } from './campaign';
import {
  resolvePresetRange,
  summarizeRecipients,
  type RecipientFilter,
  type RecipientPreview,
} from './recipient-filter';
import type { CampaignCandidate } from './campaign-filter';

/**
 * DB-facing recipient resolution. Loads every candidate (via `campaign.ts`) and
 * applies the pure rules from `recipient-filter.ts`. Kept separate from the
 * pure module so the matching logic stays unit-testable without a database.
 */

export type ResolvedRecipients = {
  eligible: CampaignCandidate[];
  preview: RecipientPreview;
};

export async function resolveRecipients(
  filter: RecipientFilter,
  now: Date = new Date(),
): Promise<ResolvedRecipients> {
  const map = await getSettingsMap();
  const cap = Number(map.campaign_max_recipients ?? 300);
  const allow = map.group_sms_allow_for_filtered === true;
  const candidates = await loadCampaignCandidates(now);
  const range = resolvePresetRange(filter, clinicDateKey(now, timezoneFrom(map)));
  return summarizeRecipients(candidates, { ...filter, ...range }, cap, allow);
}

export type PickablePatient = {
  id: string;
  fullName: string;
  phoneE164: string;
  preferredLanguage: string;
};

/** Type-ahead search for the hand-picked patient picker. */
export async function searchPickablePatients(query: string): Promise<PickablePatient[]> {
  const term = query.trim();
  return prisma.patient.findMany({
    where: term
      ? {
          active: true,
          OR: [
            { fullName: { contains: term, mode: 'insensitive' } },
            { phoneE164: { contains: term } },
          ],
        }
      : { active: true },
    select: { id: true, fullName: true, phoneE164: true, preferredLanguage: true },
    orderBy: { fullName: 'asc' },
    take: 20,
  });
}
