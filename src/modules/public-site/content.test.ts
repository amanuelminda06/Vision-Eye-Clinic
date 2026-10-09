import { describe, expect, it } from 'vitest';
import { clinicDateKey } from '@/lib/datetime';
import {
  DEFAULT_CONTENT,
  isAnnouncementVisible,
  isFaqItem,
  isTestimonial,
  parseItems,
  pick,
  resolveContent,
  type SiteContentMap,
} from './content';

describe('pick', () => {
  it('returns Amharic when present and English as a fallback', () => {
    expect(pick('am', { en: 'Hello', am: 'ሰላም' })).toBe('ሰላም');
    expect(pick('am', { en: 'Hello', am: '' })).toBe('Hello');
    expect(pick('en', { en: 'Hello', am: 'ሰላም' })).toBe('Hello');
  });
});

describe('parseItems', () => {
  it('filters invalid JSON and non-matching items', () => {
    expect(parseItems('not json', isFaqItem)).toEqual([]);
    expect(
      parseItems(JSON.stringify([{ question: 'q', answer: 'a' }, { question: 1 }]), isFaqItem),
    ).toEqual([{ question: 'q', answer: 'a' }]);
    expect(parseItems(JSON.stringify([{ name: 'A', text: 'B' }]), isTestimonial)).toEqual([
      { name: 'A', text: 'B' },
    ]);
  });
});

describe('resolveContent', () => {
  it('merges DB rows over defaults and falls back to English for blank Amharic', () => {
    const resolved = resolveContent([
      { key: 'hero_title', valueEn: 'DB title', valueAm: '' },
      { key: 'contact_phone', valueEn: '+251900000000', valueAm: '+251900000000' },
    ]);
    expect(resolved.hero_title).toEqual({ en: 'DB title', am: 'DB title' });
    expect(resolved.hero_subtitle).toEqual(DEFAULT_CONTENT.hero_subtitle);
  });
});

describe('isAnnouncementVisible', () => {
  const tz = 'Africa/Addis_Ababa';
  const now = new Date('2026-10-08T09:00:00Z');

  function content(overrides: Partial<SiteContentMap>): SiteContentMap {
    return { ...DEFAULT_CONTENT, ...overrides };
  }

  it('is hidden when inactive or empty', () => {
    expect(isAnnouncementVisible(content({}), now, tz, clinicDateKey)).toBe(false);
    expect(
      isAnnouncementVisible(
        content({
          announcement_active: { en: 'true', am: 'false' },
          announcement_text: { en: '', am: '' },
        }),
        now,
        tz,
        clinicDateKey,
      ),
    ).toBe(false);
  });

  it('reacts to the active flag and date window', () => {
    const base = {
      announcement_active: { en: 'true', am: 'false' },
      announcement_text: { en: 'Open late', am: '' },
    };
    expect(isAnnouncementVisible(content(base), now, tz, clinicDateKey)).toBe(true);
    expect(
      isAnnouncementVisible(
        content({ ...base, announcement_start: { en: '2026-10-01', am: '' } }),
        now,
        tz,
        clinicDateKey,
      ),
    ).toBe(true);
    expect(
      isAnnouncementVisible(
        content({ ...base, announcement_end: { en: '2026-10-01', am: '' } }),
        now,
        tz,
        clinicDateKey,
      ),
    ).toBe(false);
    expect(
      isAnnouncementVisible(
        content({ ...base, announcement_start: { en: '2026-10-15', am: '' } }),
        now,
        tz,
        clinicDateKey,
      ),
    ).toBe(false);
  });
});
