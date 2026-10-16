import { describe, expect, it } from 'vitest';
import type { CampaignCandidate } from './campaign-filter';
import {
  emptyRecipientFilter,
  groupAllowed,
  groupBlockReasons,
  hasCriteria,
  isPlainVisitedBetween,
  matchesRecipientFilter,
  normalizeLegacyFilter,
  normalizeRecipientFilter,
  resolvePresetRange,
  summarizeRecipients,
  type RecipientFilter,
} from './recipient-filter';

function candidate(overrides: Partial<CampaignCandidate> = {}): CampaignCandidate {
  return {
    id: 'p1',
    fullName: 'Hemnet Tesfaye',
    phoneE164: '+251911111111',
    preferredLanguage: 'en',
    doNotMessage: false,
    active: true,
    birthDate: null,
    tags: [],
    lastVisitAt: new Date('2026-09-01T10:00:00.000Z'),
    lastPurchaseAt: null,
    hasUpcoming: false,
    categories: [],
    serviceIds: [],
    ...overrides,
  };
}

function filter(overrides: Partial<RecipientFilter> = {}): RecipientFilter {
  return { ...emptyRecipientFilter(), ...overrides };
}

describe('hasCriteria / isPlainVisitedBetween', () => {
  it('an empty filter has no criteria', () => {
    expect(hasCriteria(filter())).toBe(false);
  });

  it('a plain visited-between range stays group-safe', () => {
    const f = filter({ lastVisitFrom: '2026-06-01', lastVisitTo: '2026-09-30' });
    expect(hasCriteria(f)).toBe(true);
    expect(isPlainVisitedBetween(f)).toBe(true);
  });

  it('any tag/service/category/upcoming/purchase blocks the plain case', () => {
    expect(isPlainVisitedBetween(filter({ tags: ['VIP'], lastVisitFrom: '2026-06-01' }))).toBe(false);
    expect(
      isPlainVisitedBetween(filter({ serviceIds: ['s1'], lastVisitFrom: '2026-06-01' })),
    ).toBe(false);
    expect(
      isPlainVisitedBetween(filter({ serviceCategory: 'surgery', lastVisitFrom: '2026-06-01' })),
    ).toBe(false);
    expect(isPlainVisitedBetween(filter({ upcoming: 'HAS', lastVisitFrom: '2026-06-01' }))).toBe(
      false,
    );
    expect(
      isPlainVisitedBetween(filter({ boughtServiceFrom: '2026-06-01', lastVisitFrom: '2026-06-01' })),
    ).toBe(false);
    expect(
      isPlainVisitedBetween(filter({ language: 'en', lastVisitFrom: '2026-06-01' })),
    ).toBe(false);
  });

  it('hand-picked patients are NOT a plain visited-between', () => {
    expect(
      isPlainVisitedBetween(filter({ lastVisitFrom: '2026-06-01', handPickedIds: ['p1'] })),
    ).toBe(false);
  });
});

describe('groupBlockReasons / groupAllowed', () => {
  it('blocked for tag criteria', () => {
    expect(groupBlockReasons(filter({ tags: ['VIP'] }))).toEqual(['tag']);
  });

  it('blocked for service / category / upcoming / purchase', () => {
    expect(groupBlockReasons(filter({ serviceIds: ['s1'] }))).toEqual(['service']);
    expect(groupBlockReasons(filter({ serviceCategory: 'surgery' }))).toEqual(['category']);
    expect(groupBlockReasons(filter({ upcoming: 'NONE' }))).toEqual(['upcoming']);
    expect(groupBlockReasons(filter({ boughtServiceTo: '2026-09-30' }))).toEqual(['purchase']);
  });

  it('falls back to a generic filter block', () => {
    expect(groupBlockReasons(filter({ language: 'en' }))).toEqual(['filtered']);
  });

  it('visits-only is group-safe', () => {
    expect(groupBlockReasons(filter({ lastVisitFrom: '2026-01-01' }))).toEqual([]);
  });

  it('override permits, but only when enabled', () => {
    const f = filter({ tags: ['VIP'] });
    expect(groupAllowed(f, false)).toBe(false);
    expect(groupAllowed(f, true)).toBe(true);
    expect(groupAllowed(filter({ lastVisitFrom: '2026-01-01' }), false)).toBe(true);
  });
});

describe('matchesRecipientFilter', () => {
  it('criteria match', () => {
    expect(matchesRecipientFilter(candidate({ tags: ['VIP'] }), filter({ tags: ['VIP'] }))).toBe(
      true,
    );
    expect(matchesRecipientFilter(candidate({ tags: ['VIP'] }), filter({ tags: ['Regular'] }))).toBe(
      false,
    );
  });

  it('language filter', () => {
    expect(matchesRecipientFilter(candidate({ preferredLanguage: 'am' }), filter({ language: 'am' }))).toBe(
      true,
    );
    expect(matchesRecipientFilter(candidate({ preferredLanguage: 'am' }), filter({ language: 'en' }))).toBe(
      false,
    );
  });

  it('service id filter matches any purchased service', () => {
    const cand = candidate({ serviceIds: ['s1', 's2'] });
    expect(matchesRecipientFilter(cand, filter({ serviceIds: ['s2'] }))).toBe(true);
    expect(matchesRecipientFilter(cand, filter({ serviceIds: ['s9'] }))).toBe(false);
  });

  it('like ALL vs ANY tags', () => {
    const cand = candidate({ tags: ['a', 'b'] });
    expect(matchesRecipientFilter(cand, filter({ tags: ['a', 'b'], tagMode: 'ALL' }))).toBe(true);
    expect(matchesRecipientFilter(cand, filter({ tags: ['a', 'z'], tagMode: 'ALL' }))).toBe(false);
    expect(matchesRecipientFilter(cand, filter({ tags: ['a', 'z'], tagMode: 'ANY' }))).toBe(true);
  });

  it('bought-service-between uses the purchase date', () => {
    const cand = candidate({ lastPurchaseAt: new Date('2026-08-01T00:00:00.000Z') });
    const f = filter({
      boughtServiceFrom: '2026-07-01',
      boughtServiceTo: '2026-09-01',
    });
    expect(matchesRecipientFilter(cand, f)).toBe(true);
    expect(matchesRecipientFilter(candidate(), f)).toBe(false);
  });

  it('hand-picked ids are unioned and beat the empty-criteria rule', () => {
    expect(matchesRecipientFilter(candidate(), filter({ handPickedIds: ['p1'] }))).toBe(true);
    expect(matchesRecipientFilter(candidate(), filter({}))).toBe(false);
  });

  it('upcoming HAS/NONE', () => {
    expect(matchesRecipientFilter(candidate({ hasUpcoming: true }), filter({ upcoming: 'HAS' }))).toBe(
      true,
    );
    expect(matchesRecipientFilter(candidate({ hasUpcoming: true }), filter({ upcoming: 'NONE' }))).toBe(
      false,
    );
  });
});

describe('resolvePresetRange', () => {
  it('returns explicit dates when no preset', () => {
    expect(resolvePresetRange(filter({ lastVisitFrom: '2026-01-01' }), '2026-10-10')).toEqual({
      lastVisitFrom: '2026-01-01',
      lastVisitTo: '',
    });
  });

  it('resolves last90 to a window ending today', () => {
    expect(resolvePresetRange(filter({ presetRange: 'last90' }), '2026-10-10')).toEqual({
      lastVisitFrom: '2026-07-12',
      lastVisitTo: '2026-10-10',
    });
  });

  it('resolves thisYear to Jan 1', () => {
    expect(resolvePresetRange(filter({ presetRange: 'thisYear' }), '2026-10-10')).toEqual({
      lastVisitFrom: '2026-01-01',
      lastVisitTo: '2026-10-10',
    });
  });
});

describe('normalizeRecipientFilter', () => {
  it('is defensive against bad input', () => {
    expect(normalizeRecipientFilter(null)).toEqual(emptyRecipientFilter());
    expect(normalizeRecipientFilter('nope')).toEqual(emptyRecipientFilter());
  });

  it('normalises stored JSON', () => {
    const raw = {
      tags: [' VIP ', 'x'],
      tagMode: 'ALL',
      serviceIds: ['s1'],
      language: 'en',
      upcoming: 'HAS',
      presetRange: 'last90',
      junk: true,
    };
    const result = normalizeRecipientFilter(raw);
    expect(result.tagMode).toBe('ALL');
    expect(result.language).toBe('en');
    expect(result.upcoming).toBe('HAS');
    expect(result.presetRange).toBe('last90');
    expect(result.birthdayMonth).toBe(0);
    expect(result.handPickedIds).toEqual([]);
  });
});

describe('normalizeLegacyFilter', () => {
  it('maps the old shape into the richer filter', () => {
    const legacy = {
      tags: ['VIP'],
      tagMode: 'ANY' as const,
      language: 'am' as const,
      lastVisitFrom: '2026-01-01',
      lastVisitTo: '',
      upcoming: 'ANY' as const,
      serviceCategory: '' as const,
      birthdayMonth: 0,
    };
    const result = normalizeLegacyFilter(legacy);
    expect(result.tags).toEqual(['VIP']);
    expect(result.language).toBe('am');
    expect(result.lastVisitFrom).toBe('2026-01-01');
  });
});

describe('summarizeRecipients', () => {
  it('applies hard gates and reports exclusions', () => {
    const candidates = [
      candidate({ id: 'p1' }),
      candidate({ id: 'p2' }),
      candidate({ id: 'p3', phoneE164: 'not-a-phone' }),
    ];
    const f = filter({ serviceIds: [] });
    const { eligible, preview } = summarizeRecipients(candidates, f, 300, false);
    expect(eligible.map((c) => c.id)).toEqual([]); // empty criteria matches nothing
    expect(preview.eligible).toBe(0);
  });

  it('counts criteria matches and exclusions', () => {
    const f = filter({ tags: ['VIP'] });
    const candidates = [
      candidate({ id: 'p1', tags: ['VIP'] }),
      candidate({ id: 'p2', tags: ['VIP'], doNotMessage: true }),
      candidate({ id: 'p3', tags: ['Other'] }),
      candidate({ id: 'p4', tags: ['VIP'] }),
    ];
    const { eligible, preview } = summarizeRecipients(candidates, f, 300, false);
    expect(eligible.map((c) => c.id)).toEqual(['p1', 'p4']);
    expect(preview.matched).toBe(3);
    expect(preview.eligible).toBe(2);
    expect(preview.excluded.doNotMessage).toBe(1);
    expect(preview.overCap).toBe(false);
  });

  it('flags over-cap and keeps group blockers until override', () => {
    const f = filter({ tags: ['VIP'] });
    const candidates = [candidate({ tags: ['VIP'] }), candidate({ tags: ['VIP'] })];
    const without = summarizeRecipients(candidates, f, 1, false);
    expect(without.preview.overCap).toBe(true);
    expect(without.preview.groupBlockReasons).toEqual(['tag']);
    const withOverride = summarizeRecipients(candidates, f, 1, true);
    expect(withOverride.preview.groupBlockReasons).toEqual([]);
  });
});