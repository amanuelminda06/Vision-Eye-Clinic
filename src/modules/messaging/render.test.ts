import { describe, it, expect, vi, beforeEach } from 'vitest';

const { findManyMock } = vi.hoisted(() => ({ findManyMock: vi.fn() }));
vi.mock('@/lib/prisma', () => ({
  prisma: { messageTemplate: { findMany: findManyMock } },
}));

import {
  loadTemplateBodies,
  bodyFor,
  renderMessage,
  renderVariables,
  resolveLanguage,
} from './render';
import type { MessageTemplateKey } from '@prisma/client';

const key = 'APPOINTMENT_CONFIRMATION' as MessageTemplateKey;

describe('loadTemplateBodies', () => {
  beforeEach(() => findManyMock.mockReset());

  it('builds a key:language -> body map for active templates only', async () => {
    findManyMock.mockResolvedValue([
      { key, language: 'en', body: 'Hi {name}' },
      { key, language: 'am', body: 'ሰላም {name}' },
    ]);
    expect(findManyMock).not.toHaveBeenCalled();
    const map = await loadTemplateBodies();
    expect(findManyMock).toHaveBeenCalledWith({
      where: { active: true },
      select: { key: true, language: true, body: true },
    });
    expect(map.get(`${key}:en`)).toBe('Hi {name}');
    expect(map.get(`${key}:am`)).toBe('ሰላም {name}');
  });
});

describe('bodyFor', () => {
  const bodies = new Map([
    [`${key}:am`, 'am-body'],
    [`${key}:en`, 'en-body'],
  ]);

  it('prefers the requested language', () => {
    expect(bodyFor(bodies, key, 'am')).toBe('am-body');
  });

  it('falls back to English', () => {
    expect(bodyFor(bodies, key, 'fr')).toBe('en-body');
  });

  it('returns the empty string when nothing matches', () => {
    expect(bodyFor(new Map(), key, 'en')).toBe('');
  });
});

describe('renderVariables', () => {
  const base: Parameters<typeof renderVariables>[0] = {
    patientName: 'Abebe',
    language: 'en',
    clinicName: 'Vision Eye',
    reviewLink: 'https://review.example/x',
    timeZone: 'Africa/Addis_Ababa',
  };

  it('renders static variables and date/time when startsAt is present', () => {
    const vars = renderVariables({ ...base, startsAt: new Date('2026-10-12T09:00:00.000Z') });
    expect(vars.name).toBe('Abebe');
    expect(vars.clinic).toBe('Vision Eye');
    expect(vars.review_link).toBe('https://review.example/x');
    expect(vars.date).toBeDefined();
    expect(vars.time).toBe('12:00');
  });

  it('adds doctor and reopen_date only when provided', () => {
    const vars = renderVariables({ ...base, doctorName: 'Dr B', reopenDate: '2026-11-01' });
    expect(vars.doctor).toBe('Dr B');
    expect(vars.reopen_date).toBe('2026-11-01');
    expect(vars.date).toBeUndefined();
    expect(vars.time).toBeUndefined();
  });
});

describe('renderMessage', () => {
  it('substitutes template variables in the body', () => {
    const context = {
      patientName: 'Abebe',
      language: 'en',
      clinicName: 'Vision Eye Clinic',
      reviewLink: 'https://r/x',
      timeZone: 'Africa/Addis_Ababa',
    };
    expect(renderMessage('Dear {name} at {clinic}', context)).toBe(
      'Dear Abebe at Vision Eye Clinic',
    );
  });
});

describe('resolveLanguage', () => {
  it('accepts supported languages and falls back to English otherwise', () => {
    expect(resolveLanguage('am')).toBe('am');
    expect(resolveLanguage('en')).toBe('en');
    expect(resolveLanguage(null)).toBe('en');
    expect(resolveLanguage(undefined)).toBe('en');
    expect(resolveLanguage('fr')).toBe('en');
  });
});