import { isServiceCategory, type ServiceCategory } from '@/modules/services/categories';
import type { CampaignFilterValues } from './schemas';
import { exclusionsFor, type CampaignCandidate } from './campaign-filter';
import type { BlockedReason } from './constants';

/**
 * The richer (Slice 6B) recipient filter and its pure matching rules.
 *
 * This supersedes the original `CampaignFilterValues`: it adds service-level
 * filters, a "bought service between" range, hand-picked patients, and quick
 * date presets. No DB / no React — unit-tested directly. The DB loader lives in
 * `recipients.ts`.
 */

export const RECIPIENT_PRESET_RANGES = ['', 'last30', 'last90', 'last180', 'thisYear'] as const;
export type RecipientPresetRange = (typeof RECIPIENT_PRESET_RANGES)[number];

export type RecipientFilter = {
  tags: string[];
  tagMode: 'ANY' | 'ALL';
  serviceIds: string[];
  serviceCategory: '' | ServiceCategory;
  language: '' | 'en' | 'am';
  /** "Visited between": matched against the last appointment OR sale date. */
  lastVisitFrom: string;
  lastVisitTo: string;
  /** "Bought a service between": matched against the last confirmed sale only. */
  boughtServiceFrom: string;
  boughtServiceTo: string;
  upcoming: 'ANY' | 'HAS' | 'NONE';
  birthdayMonth: number;
  /** Explicitly chosen patients — unioned with the criteria matches. */
  handPickedIds: string[];
  /** Quick preset that resolves to a `lastVisit*` date range at use time. */
  presetRange: RecipientPresetRange;
};

export function emptyRecipientFilter(): RecipientFilter {
  return {
    tags: [],
    tagMode: 'ANY',
    serviceIds: [],
    serviceCategory: '',
    language: '',
    lastVisitFrom: '',
    lastVisitTo: '',
    boughtServiceFrom: '',
    boughtServiceTo: '',
    upcoming: 'ANY',
    birthdayMonth: 0,
    handPickedIds: [],
    presetRange: '',
  };
}

/** Upgrades an old (pre-6B) stored filter to the richer shape. */
export function normalizeLegacyFilter(values: CampaignFilterValues): RecipientFilter {
  return {
    ...emptyRecipientFilter(),
    tags: values.tags,
    tagMode: values.tagMode,
    language: values.language,
    lastVisitFrom: values.lastVisitFrom,
    lastVisitTo: values.lastVisitTo,
    upcoming: values.upcoming,
    serviceCategory: values.serviceCategory,
    birthdayMonth: values.birthdayMonth,
  };
}

/** Defensive normaliser for filters read back from the DB (JSON column). */
export function normalizeRecipientFilter(input: unknown): RecipientFilter {
  const base = emptyRecipientFilter();
  if (!input || typeof input !== 'object') return base;
  const raw = input as Record<string, unknown>;
  const str = (value: unknown, fallback: string): string =>
    typeof value === 'string' ? value : fallback;
  const strArray = (value: unknown): string[] =>
    Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
  const upcoming = raw.upcoming;
  const preset = raw.presetRange;
  return {
    tags: strArray(raw.tags),
    tagMode: raw.tagMode === 'ALL' ? 'ALL' : 'ANY',
    serviceIds: strArray(raw.serviceIds),
    serviceCategory: isServiceCategory(raw.serviceCategory) ? raw.serviceCategory : '',
    language: raw.language === 'en' || raw.language === 'am' ? raw.language : '',
    lastVisitFrom: str(raw.lastVisitFrom, base.lastVisitFrom),
    lastVisitTo: str(raw.lastVisitTo, base.lastVisitTo),
    boughtServiceFrom: str(raw.boughtServiceFrom, base.boughtServiceFrom),
    boughtServiceTo: str(raw.boughtServiceTo, base.boughtServiceTo),
    upcoming: upcoming === 'HAS' || upcoming === 'NONE' ? upcoming : 'ANY',
    birthdayMonth: typeof raw.birthdayMonth === 'number' ? raw.birthdayMonth : 0,
    handPickedIds: strArray(raw.handPickedIds),
    presetRange: (RECIPIENT_PRESET_RANGES as readonly unknown[]).includes(preset)
      ? (preset as RecipientPresetRange)
      : '',
  };
}

/** Resolves a quick preset into an explicit "last visit" date range. */
export function resolvePresetRange(
  filter: RecipientFilter,
  todayKey: string,
): Pick<RecipientFilter, 'lastVisitFrom' | 'lastVisitTo'> {
  if (!filter.presetRange) {
    return { lastVisitFrom: filter.lastVisitFrom, lastVisitTo: filter.lastVisitTo };
  }
  const days = (count: number): string => {
    const [y, m, d] = todayKey.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d - count));
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${dt.getUTCFullYear()}-${pad(dt.getUTCMonth() + 1)}-${pad(dt.getUTCDate())}`;
  };
  if (filter.presetRange === 'thisYear') {
    return { lastVisitFrom: `${todayKey.slice(0, 4)}-01-01`, lastVisitTo: todayKey };
  }
  const back = filter.presetRange === 'last30' ? 30 : filter.presetRange === 'last90' ? 90 : 180;
  return { lastVisitFrom: days(back), lastVisitTo: todayKey };
}

function dateKeyOf(date: Date | null): string | null {
  return date ? date.toISOString().slice(0, 10) : null;
}

/** Whether the filter has any criteria (hand-picked patients alone don't count). */
export function hasCriteria(filter: RecipientFilter): boolean {
  return (
    filter.tags.length > 0 ||
    filter.serviceIds.length > 0 ||
    filter.serviceCategory !== '' ||
    filter.language !== '' ||
    filter.lastVisitFrom !== '' ||
    filter.lastVisitTo !== '' ||
    filter.boughtServiceFrom !== '' ||
    filter.boughtServiceTo !== '' ||
    filter.upcoming !== 'ANY' ||
    filter.birthdayMonth > 0 ||
    filter.presetRange !== ''
  );
}

/**
 * "Plain visited between": only a last-visit date range is set — the one
 * filtered case that stays group-safe by default (per the agreed rule).
 */
export function isPlainVisitedBetween(filter: RecipientFilter): boolean {
  return (
    filter.tags.length === 0 &&
    filter.serviceIds.length === 0 &&
    filter.serviceCategory === '' &&
    filter.language === '' &&
    filter.boughtServiceFrom === '' &&
    filter.boughtServiceTo === '' &&
    filter.upcoming === 'ANY' &&
    filter.birthdayMonth === 0 &&
    (filter.presetRange === '' || filter.presetRange === 'last30' || filter.presetRange === 'last90' || filter.presetRange === 'last180') &&
    (filter.lastVisitFrom !== '' || filter.lastVisitTo !== '') &&
    filter.handPickedIds.length === 0
  );
}

/**
 * Default group-mode block reasons — i18n suffixes under `messages.groupBlock.*`.
 * An empty array means the filter is group-safe without any override.
 */
export function groupBlockReasons(filter: RecipientFilter): string[] {
  if (!hasCriteria(filter)) return [];
  if (isPlainVisitedBetween(filter)) return [];
  const reasons: string[] = [];
  if (filter.serviceIds.length > 0) reasons.push('service');
  if (filter.tags.length > 0) reasons.push('tag');
  if (filter.serviceCategory !== '') reasons.push('category');
  if (filter.upcoming !== 'ANY') reasons.push('upcoming');
  if (filter.boughtServiceFrom !== '' || filter.boughtServiceTo !== '') reasons.push('purchase');
  if (reasons.length === 0) reasons.push('filtered');
  return reasons;
}

/** Whether group delivery is permitted (override only covers filtered cards). */
export function groupAllowed(filter: RecipientFilter, allowFiltered: boolean): boolean {
  const reasons = groupBlockReasons(filter);
  if (reasons.length === 0) return true;
  return allowFiltered;
}

function withinRange(dateKey: string | null, from: string, to: string): boolean {
  if (!dateKey) return false;
  if (from && dateKey < from) return false;
  if (to && dateKey > to) return false;
  return true;
}

/** Criteria-only match (excludes the hand-picked union and hard gates). */
function matchesCriteria(candidate: CampaignCandidate, filter: RecipientFilter): boolean {
  if (filter.tags.length > 0) {
    const lower = candidate.tags.map((tag) => tag.toLowerCase());
    const wanted = filter.tags.map((tag) => tag.toLowerCase());
    const hit =
      filter.tagMode === 'ALL'
        ? wanted.every((tag) => lower.includes(tag))
        : wanted.some((tag) => lower.includes(tag));
    if (!hit) return false;
  }

  if (filter.serviceIds.length > 0) {
    if (!candidate.serviceIds.some((id) => filter.serviceIds.includes(id))) return false;
  }

  if (filter.serviceCategory && !candidate.categories.includes(filter.serviceCategory)) return false;

  if (filter.language && candidate.preferredLanguage !== filter.language) return false;

  if (filter.lastVisitFrom || filter.lastVisitTo) {
    if (!withinRange(dateKeyOf(candidate.lastVisitAt), filter.lastVisitFrom, filter.lastVisitTo)) {
      return false;
    }
  }

  if (filter.boughtServiceFrom || filter.boughtServiceTo) {
    if (
      !withinRange(dateKeyOf(candidate.lastPurchaseAt), filter.boughtServiceFrom, filter.boughtServiceTo)
    ) {
      return false;
    }
  }

  if (filter.upcoming === 'HAS' && !candidate.hasUpcoming) return false;
  if (filter.upcoming === 'NONE' && candidate.hasUpcoming) return false;

  if (filter.birthdayMonth > 0) {
    if (!candidate.birthDate || candidate.birthDate.getUTCMonth() + 1 !== filter.birthdayMonth) {
      return false;
    }
  }

  return true;
}

/**
 * Full match: criteria matches unioned with hand-picked ids. Hard gates
 * (inactive/phone) are applied separately via `exclusionsFor`.
 */
export function matchesRecipientFilter(
  candidate: CampaignCandidate,
  filter: RecipientFilter,
): boolean {
  if (filter.handPickedIds.includes(candidate.id)) return true;
  if (!hasCriteria(filter)) return false;
  return matchesCriteria(candidate, filter);
}

export type RecipientPreview = {
  matched: number;
  eligible: number;
  excluded: Record<BlockedReason, number>;
  cap: number;
  overCap: boolean;
  groupSafe: boolean;
  groupBlockReasons: string[];
};

export function summarizeRecipients(
  candidates: CampaignCandidate[],
  filter: RecipientFilter,
  cap: number,
  allowFilteredGroup: boolean,
): { eligible: CampaignCandidate[]; preview: RecipientPreview } {
  const filtered = candidates.filter((candidate) => matchesRecipientFilter(candidate, filter));
  const excluded: Record<BlockedReason, number> = {
    inactive: 0,
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
  const blockReasons = groupBlockReasons(filter);
  return {
    eligible,
    preview: {
      matched: filtered.length,
      eligible: eligible.length,
      excluded,
      cap,
      overCap: eligible.length > cap,
      groupSafe: blockReasons.length === 0,
      groupBlockReasons: allowFilteredGroup && blockReasons.length > 0 ? [] : blockReasons,
    },
  };
}
