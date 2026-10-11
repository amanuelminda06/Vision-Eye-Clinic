import { describe, it, expect } from 'vitest';
import {
  appointmentConfirmationSchedule,
  appointmentReminderSchedule,
  appointmentSameDaySchedule,
  noShowSchedule,
  followUpSchedule,
  thankYouSchedule,
  birthdaySchedule,
  recallSchedule,
  immediateSchedule,
  recallPeriodKey,
  laterOf,
  DEFAULT_MESSAGING_SETTINGS,
} from './schedule';

const TZ = 'Africa/Addis_Ababa'; // UTC+3, no DST — easy to reason about
const S = DEFAULT_MESSAGING_SETTINGS;

const at = (iso: string) => new Date(iso);

describe('appointmentConfirmationSchedule', () => {
  it('is due now and expires at end of clinic day', () => {
    const now = at('2026-10-10T07:30:00.000Z');
    const s = appointmentConfirmationSchedule(now, S);
    expect(s.dueAt).toEqual(now);
    expect(s.expiresAt).toBeNull();
    expect(s.sendBy!.toISOString()).toBe('2026-10-10T21:00:00.000Z'); // next local midnight
  });
});

describe('appointmentReminderSchedule', () => {
  const appt = { id: 'a', startsAt: at('2026-10-13T09:00:00.000Z'), createdAt: at('2026-10-01') };

  it('returns null for appointments that already started', () => {
    expect(appointmentReminderSchedule(appt, S, at('2026-10-13T09:00:00.000Z'))).toBeNull();
  });

  it('schedules the day before at reminder time, expiring at start', () => {
    const s = appointmentReminderSchedule(appt, S, at('2026-10-09'))!;
    expect(s.dueAt.toISOString()).toBe('2026-10-12T11:00:00.000Z'); // 14:00 local previous day
    expect(s.sendBy!.toISOString()).toBe('2026-10-13T08:00:00.000Z'); // startsAt - 60min
    expect(s.expiresAt).toEqual(appt.startsAt);
  });

  it('falls due immediately for late bookings created after dueAt', () => {
    const late = { ...appt, createdAt: at('2026-10-12T12:00:00.000Z') };
    const s = appointmentReminderSchedule(late, S, at('2026-10-12T15:00:00.000Z'))!;
    expect(s.dueAt.toISOString()).toBe('2026-10-12T15:00:00.000Z');
  });
});

describe('appointmentSameDaySchedule', () => {
  const appt = { id: 'a', startsAt: at('2026-10-13T09:00:00.000Z'), createdAt: at('2026-10-01') };

  it('is due at 08:00 local on the morning of the appointment (before 08:00)', () => {
    const now = at('2026-10-12T21:00:00.000Z'); // Oct 13 00:00 local
    const s = appointmentSameDaySchedule(appt, S, now)!;
    expect(s.dueAt.toISOString()).toBe('2026-10-13T05:00:00.000Z');
    expect(s.sendBy).toEqual(appt.startsAt);
    expect(s.expiresAt).toEqual(appt.startsAt);
  });

  it('falls due immediately when the 08:00 reminder window passes before the appointment day', () => {
    const now = at('2026-10-12T20:00:00.000Z');
    const s = appointmentSameDaySchedule(appt, S, now)!;
    expect(s.dueAt).toEqual(now);
    expect(s.sendBy).toEqual(appt.startsAt);
  });

  it('falls due immediately when the 08:00 window already passed', () => {
    const now = at('2026-10-13T06:30:00.000Z');
    const s = appointmentSameDaySchedule(appt, S, now)!;
    expect(s.dueAt.toISOString()).toBe('2026-10-13T06:30:00.000Z');
  });

  it('returns null once the appointment started', () => {
    expect(appointmentSameDaySchedule(appt, S, at('2026-10-13T09:00:00.000Z'))).toBeNull();
  });
});

describe('noShowSchedule', () => {
  it('is due next morning 09:00 local, valid for 72h', () => {
    const s = noShowSchedule(at('2026-10-10T15:00:00.000Z'), S);
    expect(s.dueAt.toISOString()).toBe('2026-10-11T06:00:00.000Z');
    expect(s.sendBy).toBeNull();
    expect(s.expiresAt!.toISOString()).toBe('2026-10-14T06:00:00.000Z');
  });
});

describe('followUpSchedule', () => {
  it('leads by followup_lead_days and expires 7 days after the due date', () => {
    const due = at('2026-10-20T00:00:00.000Z');
    const s = followUpSchedule(due, S, at('2026-10-01'));
    expect(s.dueAt.toISOString()).toBe('2026-10-17T06:00:00.000Z');
    expect(s.sendBy).toBeNull();
    expect(s.expiresAt!.toISOString()).toBe('2026-10-27T20:59:00.000Z'); // +7d 23:59 local
  });

  it('falls due to now when the lead window already passed', () => {
    const now = at('2026-10-18T00:00:00.000Z');
    const s = followUpSchedule(at('2026-10-20T00:00:00.000Z'), S, now);
    expect(s.dueAt).toEqual(now);
  });
});

describe('thankYouSchedule', () => {
  it('is due the morning after the appointment', () => {
    const s = thankYouSchedule(
      { id: 'a', startsAt: at('2026-10-10T09:00:00.000Z'), createdAt: at('2026-10-01') },
      S,
    );
    expect(s.dueAt.toISOString()).toBe('2026-10-11T06:00:00.000Z');
    expect(s.sendBy).toBeNull();
    expect(s.expiresAt).toBeNull();
  });
});

describe('birthdaySchedule', () => {
  it('is due at 08:00 local today and expires at next local midnight', () => {
    const s = birthdaySchedule(at('2026-10-10T20:00:00.000Z'), S);
    expect(s.dueAt.toISOString()).toBe('2026-10-10T05:00:00.000Z');
    expect(s.sendBy).toBeNull();
    expect(s.expiresAt!.toISOString()).toBe('2026-10-10T21:00:00.000Z'); // next local midnight
  });
});

describe('recall / immediate', () => {
  it('recall is due now and valid for 30 days', () => {
    const now = at('2026-10-10T12:00:00.000Z');
    const s = recallSchedule(now);
    expect(s.dueAt).toEqual(now);
    expect(s.expiresAt!.toISOString()).toBe('2026-11-09T12:00:00.000Z');
  });

  it('immediate task is due now, never expires', () => {
    const now = at('2026-10-10T12:00:00.000Z');
    expect(immediateSchedule(now)).toEqual({ dueAt: now, sendBy: null, expiresAt: null });
  });
});

describe('recallPeriodKey / laterOf', () => {
  it('gives a YYYY-MM idempotency key in clinic time', () => {
    expect(recallPeriodKey(at('2026-10-10T22:00:00.000Z'), TZ)).toBe('2026-10');
    // local date is the same regardless of UTC day shift while inside the TZ
    expect(recallPeriodKey(at('2026-10-31T20:00:00.000Z'), TZ)).toBe('2026-10'); // 23:00 local
    expect(recallPeriodKey(at('2026-10-31T21:00:00.000Z'), TZ)).toBe('2026-11'); // 00:00 local
  });

  it('laterOf returns the latest instant', () => {
    const a = at('2026-01-01');
    const b = at('2026-06-01');
    expect(laterOf(a, b)).toBe(b);
    expect(laterOf(b, a)).toBe(b);
    expect(laterOf(a, a)).toBe(a);
  });
});