import { describe, expect, it } from 'vitest';
import {
  countSms,
  previewTemplate,
  renderTemplate,
  unknownVariables,
  validateTemplateBody,
} from './templates';
import { buildSmsLink, detectPlatform, getSmsProvider } from './provider';
import { blockedReason, isEligible } from './eligibility';
import {
  appointmentReminderSchedule,
  appointmentSameDaySchedule,
  birthdaySchedule,
  followUpSchedule,
  noShowSchedule,
  thankYouSchedule,
  DEFAULT_MESSAGING_SETTINGS,
  type MessagingSettings,
} from './schedule';
import {
  matchesFilter,
  exclusionsFor,
  summarizeCandidates,
  type CampaignCandidate,
} from './campaign-filter';
import { canSendCampaigns, canSendMessages } from './roles';
import { combineDateAndTime } from '@/lib/datetime';
import type { CampaignFilterValues } from './schemas';

const settings: MessagingSettings = { ...DEFAULT_MESSAGING_SETTINGS };

function addis(dateKey: string, time: string): Date {
  return combineDateAndTime(dateKey, time, settings.timeZone);
}

function candidate(overrides: Partial<CampaignCandidate> = {}): CampaignCandidate {
  return {
    id: 'p1',
    fullName: 'Hemnet Tesfaye',
    phoneE164: '+251911000000',
    preferredLanguage: 'en',
    doNotMessage: false,
    active: true,
    birthDate: null,
    tags: [],
    lastVisitAt: null,
    lastPurchaseAt: null,
    hasUpcoming: false,
    categories: [],
    serviceIds: [],
    ...overrides,
  };
}

const emptyFilter: CampaignFilterValues = {
  tags: [],
  tagMode: 'ANY',
  language: '',
  lastVisitFrom: '',
  lastVisitTo: '',
  upcoming: 'ANY',
  serviceCategory: '',
  birthdayMonth: 0,
};

describe('template rendering', () => {
  it('substitutes known variables and strips unknown ones', () => {
    expect(renderTemplate('Hi {name} at {clinic}', { name: 'Almaz', clinic: 'VEC' })).toBe(
      'Hi Almaz at VEC',
    );
    expect(renderTemplate('Hi {nope}', { name: 'Almaz' })).toBe('Hi ');
  });

  it('drops lines that reference an empty review_link', () => {
    const body = 'Thanks for visiting.\nLeave a review: {review_link}\nSee you soon.';
    expect(renderTemplate(body, { review_link: '' })).toBe('Thanks for visiting.\nSee you soon.');
    expect(renderTemplate(body, { review_link: 'https://x.y' })).toContain('https://x.y');
  });

  it('flags unknown variables', () => {
    expect(unknownVariables('Hello {name} {bogus}')).toEqual(['bogus']);
    expect(validateTemplateBody('Hello {name}').ok).toBe(true);
    expect(validateTemplateBody('Hello {bogus}').ok).toBe(false);
  });
});

describe('SMS character counting', () => {
  it('counts GSM-7 messages', () => {
    expect(countSms('hello').encoding).toBe('GSM-7');
    expect(countSms('a'.repeat(161)).parts).toBe(2);
    expect(countSms('a'.repeat(160)).parts).toBe(1);
  });

  it('counts extension characters as two', () => {
    expect(countSms('{').length).toBe(2);
  });

  it('switches to Unicode for Amharic', () => {
    const result = countSms('ሰላም ለአንተ');
    expect(result.encoding).toBe('Unicode');
    expect(result.parts).toBe(1);
    expect(countSms('ሰ'.repeat(71)).parts).toBe(2);
  });

  it('renders a preview with sample data', () => {
    const preview = previewTemplate('Hi {name}, see you {date} at {time}.');
    expect(preview.text).toContain('Hemnet Tesfaye');
    expect(preview.unknown).toEqual([]);
  });
});

describe('sms: link builder', () => {
  it('uses ?body= on Android and &body= on iOS', () => {
    expect(buildSmsLink('+251911000000', 'hello', 'android')).toBe('sms:+251911000000?body=hello');
    expect(buildSmsLink('+251911000000', 'hello', 'ios')).toBe('sms:+251911000000&body=hello');
  });

  it('percent-encodes Amharic and line breaks', () => {
    const link = buildSmsLink('+251911000000', 'ሰላም\nbye', 'android');
    expect(link).toContain('%0A');
    expect(link).not.toContain('\n');
    expect(link).toContain(encodeURIComponent('ሰላም'));
  });

  it('detects platform and falls back to manual provider', () => {
    expect(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)')).toBe('ios');
    expect(detectPlatform('Mozilla/5.0 (Linux; Android 14)')).toBe('android');
    expect(detectPlatform(null)).toBe('other');
    expect(getSmsProvider('nonsense').mode).toBe('MANUAL_LINK');
    expect(getSmsProvider('GATEWAY').mode).toBe('GATEWAY');
  });
});

describe('eligibility', () => {
  it('blocks for each reason', () => {
    expect(blockedReason({ patient: { ...candidate(), active: false } })).toBe('inactive');
    expect(blockedReason({ patient: { ...candidate(), doNotMessage: true } })).toBe('doNotMessage');
    expect(blockedReason({ patient: { ...candidate(), phoneE164: '12345' } })).toBe('invalidPhone');
    expect(blockedReason({ patient: candidate(), sourceStatus: 'CANCELLED' })).toBe(
      'sourceCancelled',
    );
    expect(isEligible({ patient: candidate() })).toBe(true);
  });
});

describe('appointment scheduling (Africa/Addis_Ababa)', () => {
  it('schedules the reminder for the day before at 14:00', () => {
    const startsAt = addis('2026-10-12', '10:00');
    const now = addis('2026-10-09', '09:00');
    const schedule = appointmentReminderSchedule(
      { id: 'a1', startsAt, createdAt: now },
      settings,
      now,
    );
    expect(schedule?.dueAt.toISOString()).toBe(addis('2026-10-11', '14:00').toISOString());
    expect(schedule?.expiresAt?.toISOString()).toBe(startsAt.toISOString());
  });

  it('makes a late booking due immediately', () => {
    const startsAt = addis('2026-10-12', '10:00');
    const createdAt = addis('2026-10-11', '16:00');
    const schedule = appointmentReminderSchedule(
      { id: 'a1', startsAt, createdAt },
      settings,
      createdAt,
    );
    expect(schedule?.dueAt.toISOString()).toBe(createdAt.toISOString());
  });

  it('returns null once the appointment has started', () => {
    const startsAt = addis('2026-10-12', '10:00');
    expect(
      appointmentReminderSchedule({ id: 'a1', startsAt, createdAt: startsAt }, settings, startsAt),
    ).toBeNull();
  });

  it('schedules same-day at 08:00 and no-show the next morning', () => {
    const now = addis('2026-10-12', '06:00');
    const startsAt = addis('2026-10-12', '10:00');
    expect(
      appointmentSameDaySchedule(
        { id: 'a1', startsAt, createdAt: now },
        settings,
        now,
      )?.dueAt.toISOString(),
    ).toBe(addis('2026-10-12', '08:00').toISOString());
    expect(noShowSchedule(now, settings).dueAt.toISOString()).toBe(
      addis('2026-10-13', '09:00').toISOString(),
    );
  });

  it('schedules thank-you, follow-up and birthday from local keys', () => {
    const startsAt = addis('2026-10-12', '11:00');
    expect(
      thankYouSchedule({ id: 'a1', startsAt, createdAt: startsAt }, settings).dueAt.toISOString(),
    ).toBe(addis('2026-10-13', '09:00').toISOString());
    const due = addis('2026-10-20', '09:00');
    expect(followUpSchedule(due, settings, addis('2026-10-09', '09:00')).dueAt.toISOString()).toBe(
      addis('2026-10-17', '09:00').toISOString(),
    );
    const birthdayNow = addis('2026-10-12', '06:00');
    const birthday = birthdaySchedule(birthdayNow, settings);
    expect(birthday.dueAt.toISOString()).toBe(addis('2026-10-12', '08:00').toISOString());
    expect(birthday.expiresAt?.toISOString()).toBe(
      combineDateAndTime('2026-10-13', '00:00', settings.timeZone).toISOString(),
    );
  });
});

describe('campaign filters', () => {
  it('matches tags with ANY/ALL and other criteria', () => {
    const c = candidate({
      tags: ['glasses', 'vip'],
      lastVisitAt: addis('2026-01-01', '09:00'),
      categories: ['glasses'],
    });
    expect(matchesFilter(c, { ...emptyFilter, tags: ['vip'], tagMode: 'ANY' })).toBe(true);
    expect(matchesFilter(c, { ...emptyFilter, tags: ['vip', 'surgery'], tagMode: 'ALL' })).toBe(
      false,
    );
    expect(matchesFilter(c, { ...emptyFilter, serviceCategory: 'glasses' })).toBe(true);
    expect(matchesFilter(c, { ...emptyFilter, serviceCategory: 'surgery' })).toBe(false);
    expect(
      matchesFilter(c, { ...emptyFilter, lastVisitFrom: '2025-01-01', lastVisitTo: '2025-12-31' }),
    ).toBe(false);
    expect(matchesFilter(candidate(), { ...emptyFilter, upcoming: 'HAS' })).toBe(false);
    expect(
      matchesFilter(candidate({ birthDate: new Date('1990-10-12T00:00:00Z') }), {
        ...emptyFilter,
        birthdayMonth: 10,
      }),
    ).toBe(true);
  });

  it('always excludes ineligible recipients and reports why', () => {
    expect(exclusionsFor(candidate({ doNotMessage: true }))).toContain('doNotMessage');
    expect(exclusionsFor(candidate({ phoneE164: 'bad' }))).toContain('invalidPhone');
    expect(exclusionsFor(candidate({ active: false }))).toContain('inactive');
    expect(exclusionsFor(candidate())).toEqual([]);
  });

  it('summarizes eligible vs excluded and flags the cap', () => {
    const list = [
      candidate({ id: 'a' }),
      candidate({ id: 'b', doNotMessage: true }),
      candidate({ id: 'c' }),
    ];
    const { eligible, preview } = summarizeCandidates(list, emptyFilter, 1);
    expect(eligible.map((c) => c.id)).toEqual(['a', 'c']);
    expect(preview.excluded.doNotMessage).toBe(1);
    expect(preview.overCap).toBe(true);
  });
});

describe('campaign role rules', () => {
  it('restricts campaigns and templates appropriately', () => {
    expect(canSendMessages('RECEPTION')).toBe(true);
    expect(canSendMessages('STAFF')).toBe(false);
    expect(canSendCampaigns('OWNER', false)).toBe(true);
    expect(canSendCampaigns('RECEPTION', false)).toBe(false);
    expect(canSendCampaigns('RECEPTION', true)).toBe(true);
  });
});
