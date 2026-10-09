import { describe, expect, it } from 'vitest';
import type { WorkingHours } from './format';
import { telHref, telegramHref, whatsappHref, workingHoursLines } from './format';

const HOURS: WorkingHours = {
  mon: { open: '08:00', close: '17:00', closed: false },
  tue: { open: '08:00', close: '17:00', closed: false },
  wed: { open: '08:00', close: '17:00', closed: false },
  thu: { open: '08:00', close: '17:00', closed: false },
  fri: { open: '08:00', close: '17:00', closed: false },
  sat: { open: '08:00', close: '12:00', closed: false },
  sun: { open: '00:00', close: '00:00', closed: true },
};

describe('contact links', () => {
  it('normalises phone numbers to tel: links', () => {
    expect(telHref('+251 911 223 344')).toBe('tel:+251911223344');
    expect(telHref('0911223344')).toBe('tel:0911223344');
    expect(telHref('123')).toBeNull();
    expect(telHref(null)).toBeNull();
    expect(telHref('')).toBeNull();
  });

  it('builds WhatsApp links from numbers or full URLs', () => {
    expect(whatsappHref('251900000000')).toBe('https://wa.me/251900000000');
    expect(whatsappHref('+251 900 000 000')).toBe('https://wa.me/251900000000');
    expect(whatsappHref('https://wa.me/251900000000')).toBe('https://wa.me/251900000000');
    expect(whatsappHref('')).toBeNull();
  });

  it('builds Telegram links from handles or URLs', () => {
    expect(telegramHref('@visioneye')).toBe('https://t.me/visioneye');
    expect(telegramHref('visioneye')).toBe('https://t.me/visioneye');
    expect(telegramHref('https://t.me/visioneye')).toBe('https://t.me/visioneye');
    expect(telegramHref('')).toBeNull();
  });
});

describe('workingHoursLines', () => {
  it('renders every day in order and marks closed days', () => {
    const lines = workingHoursLines(HOURS, (day) => day, 'Closed');
    expect(lines).toHaveLength(7);
    expect(lines[0]).toEqual({ day: 'mon', value: '08:00–17:00' });
    expect(lines[6]).toEqual({ day: 'sun', value: 'Closed' });
  });
});
