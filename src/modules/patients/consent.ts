/** Pure SMS-consent state helpers (unit-tested) — who recorded consent, when. */

export type ConsentFields = {
  consentSms: boolean;
  consentDate: Date | null;
  consentRecordedBy: string | null;
};

/** Consent snapshot for a new patient: date + user recorded only when granted. */
export function initialConsent(wantsConsent: boolean, now: Date, userId: string): ConsentFields {
  return wantsConsent
    ? { consentSms: true, consentDate: now, consentRecordedBy: userId }
    : { consentSms: false, consentDate: null, consentRecordedBy: null };
}

/**
 * Consent snapshot after an edit:
 * - consent kept    → original date/user preserved (no re-recording)
 * - consent granted → now + actor
 * - consent removed → cleared (the audit log keeps the history)
 */
export function nextConsent(
  before: ConsentFields,
  wantsConsent: boolean,
  now: Date,
  userId: string,
): ConsentFields {
  if (!wantsConsent) return { consentSms: false, consentDate: null, consentRecordedBy: null };
  if (before.consentSms) return before;
  return { consentSms: true, consentDate: now, consentRecordedBy: userId };
}

function sameInstant(a: Date | null, b: Date | null): boolean {
  if (a === null || b === null) return a === b;
  return a.getTime() === b.getTime();
}

/**
 * True when the consent fields changed — used to emit the dedicated
 * `PATIENT_CONSENT_CHANGED` audit entry (e.g. when consent is un-ticked).
 */
export function consentChanged(before: ConsentFields, after: ConsentFields): boolean {
  return (
    before.consentSms !== after.consentSms ||
    !sameInstant(before.consentDate, after.consentDate) ||
    before.consentRecordedBy !== after.consentRecordedBy
  );
}

/** The audit old/new pair for a consent change (null when unchanged). */
export function consentAuditEntry(
  before: ConsentFields,
  after: ConsentFields,
): { old: ConsentFields; new: ConsentFields } | null {
  return consentChanged(before, after) ? { old: before, new: after } : null;
}
