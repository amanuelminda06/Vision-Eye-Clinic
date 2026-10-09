import { describe, expect, it } from 'vitest';
import {
  consentAuditEntry,
  consentChanged,
  initialConsent,
  nextConsent,
  type ConsentFields,
} from './consent';

const NOW = new Date('2026-10-08T10:00:00.000Z');
const LATER = new Date('2026-10-09T10:00:00.000Z');

const none: ConsentFields = { consentSms: false, consentDate: null, consentRecordedBy: null };

describe('initialConsent', () => {
  it('records date + user only when consent is given', () => {
    expect(initialConsent(true, NOW, 'user-1')).toEqual({
      consentSms: true,
      consentDate: NOW,
      consentRecordedBy: 'user-1',
    });
    expect(initialConsent(false, NOW, 'user-1')).toEqual(none);
  });
});

describe('nextConsent', () => {
  it('keeps the original date/user when consent is unchanged', () => {
    const before: ConsentFields = {
      consentSms: true,
      consentDate: NOW,
      consentRecordedBy: 'user-1',
    };
    expect(nextConsent(before, true, LATER, 'user-2')).toEqual(before);
  });

  it('records now + actor when consent is granted for the first time', () => {
    expect(nextConsent(none, true, LATER, 'user-2')).toEqual({
      consentSms: true,
      consentDate: LATER,
      consentRecordedBy: 'user-2',
    });
  });

  it('clears date/user when consent is withdrawn', () => {
    const before: ConsentFields = {
      consentSms: true,
      consentDate: NOW,
      consentRecordedBy: 'user-1',
    };
    expect(nextConsent(before, false, LATER, 'user-2')).toEqual(none);
  });
});

describe('consentChanged / consentAuditEntry', () => {
  it('is false when nothing changed', () => {
    const value: ConsentFields = { consentSms: true, consentDate: NOW, consentRecordedBy: 'u' };
    expect(consentChanged(value, { ...value })).toBe(false);
    expect(consentAuditEntry(value, { ...value })).toBeNull();
  });

  it('emits the audit pair when consent is un-ticked', () => {
    const before: ConsentFields = {
      consentSms: true,
      consentDate: NOW,
      consentRecordedBy: 'user-1',
    };
    const after = nextConsent(before, false, LATER, 'user-2');
    expect(consentChanged(before, after)).toBe(true);
    expect(consentAuditEntry(before, after)).toEqual({ old: before, new: after });
  });

  it('emits the audit pair when consent is first given', () => {
    const after = nextConsent(none, true, NOW, 'user-1');
    expect(consentAuditEntry(none, after)).toEqual({ old: none, new: after });
  });
});
