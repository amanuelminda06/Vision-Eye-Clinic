import { isValidPhone } from '@/lib/phone';
import type { BlockedReason } from './constants';
import type { CampaignFilterValues } from './schemas';

/**
 * Pure campaign audience matching. Kept free of DB/server imports so it can be
 * unit-tested directly; the DB loader and materialisation live in `campaign.ts`.
 */

export type CampaignCandidate = {
  id: string;
  fullName: string;
  phoneE164: string;
  preferredLanguage: string;
  consentSms: boolean;
  doNotMessage: boolean;
  active: boolean;
  birthDate: Date | null;
  tags: string[];
  lastVisitAt: Date | null;
  /** Date of the most recent CONFIRMED sale, if any (for "bought between"). */
  lastPurchaseAt: Date | null;
  hasUpcoming: boolean;
  categories: string[];
  /** Service ids purchased (for service-level filters). */
  serviceIds: string[];
};

export function matchesFilter(candidate: CampaignCandidate, filter: CampaignFilterValues): boolean {
  if (filter.tags.length > 0) {
    const lower = candidate.tags.map((tag) => tag.toLowerCase());
    const wanted = filter.tags.map((tag) => tag.toLowerCase());
    const hit =
      filter.tagMode === 'ALL'
        ? wanted.every((tag) => lower.includes(tag))
        : wanted.some((tag) => lower.includes(tag));
    if (!hit) return false;
  }

  if (filter.language && candidate.preferredLanguage !== filter.language) return false;

  if (filter.lastVisitFrom || filter.lastVisitTo) {
    if (!candidate.lastVisitAt) return false;
    const key = candidate.lastVisitAt.toISOString().slice(0, 10);
    if (filter.lastVisitFrom && key < filter.lastVisitFrom) return false;
    if (filter.lastVisitTo && key > filter.lastVisitTo) return false;
  }

  if (filter.upcoming === 'HAS' && !candidate.hasUpcoming) return false;
  if (filter.upcoming === 'NONE' && candidate.hasUpcoming) return false;

  if (filter.serviceCategory && !candidate.categories.includes(filter.serviceCategory))
    return false;

  if (filter.birthdayMonth > 0) {
    if (!candidate.birthDate || candidate.birthDate.getUTCMonth() + 1 !== filter.birthdayMonth) {
      return false;
    }
  }

  return true;
}

/** Hard, non-removable gates. Consent is always enforced. */
export function exclusionsFor(candidate: CampaignCandidate): BlockedReason[] {
  const reasons: BlockedReason[] = [];
  if (!candidate.active) reasons.push('inactive');
  if (!candidate.consentSms) reasons.push('noConsent');
  if (candidate.doNotMessage) reasons.push('doNotMessage');
  if (!isValidPhone(candidate.phoneE164)) reasons.push('invalidPhone');
  return reasons;
}

export type CampaignPreview = {
  matched: number;
  eligible: number;
  excluded: Record<BlockedReason, number>;
  cap: number;
  overCap: boolean;
};

export function summarizeCandidates(
  candidates: CampaignCandidate[],
  filter: CampaignFilterValues,
  cap: number,
): { eligible: CampaignCandidate[]; preview: CampaignPreview } {
  const filtered = candidates.filter((candidate) => matchesFilter(candidate, filter));
  const excluded: Record<BlockedReason, number> = {
    inactive: 0,
    noConsent: 0,
    doNotMessage: 0,
    invalidPhone: 0,
    sourceCancelled: 0,
  };
  const eligible: CampaignCandidate[] = [];
  for (const candidate of filtered) {
    const reasons = exclusionsFor(candidate);
    if (reasons.length === 0) eligible.push(candidate);
    else for (const reason of reasons) excluded[reason] += 1;
  }
  return {
    eligible,
    preview: {
      matched: filtered.length,
      eligible: eligible.length,
      excluded,
      cap,
      overCap: eligible.length > cap,
    },
  };
}
