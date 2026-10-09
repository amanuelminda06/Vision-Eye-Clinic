/**
 * Phone normalization (rule 8) → E.164 (+2519XXXXXXXX / +2517XXXXXXXX).
 * Accepts: 09XXXXXXXX, 07XXXXXXXX, 9XXXXXXXX, 7XXXXXXXX,
 * +2519XXXXXXXX, +2517XXXXXXXX (spaces, dashes, dots and parentheses tolerated).
 * Everything else is rejected with a machine-readable error code.
 */

const MOBILE_NATIONAL = /^0[97]\d{8}$/;
const MOBILE_SHORT = /^[97]\d{8}$/;
const MOBILE_E164 = /^251[97]\d{8}$/;

/** Why a phone input was rejected — maps to `validation.*` i18n keys. */
export type PhoneError = 'required' | 'invalidPhone';

export type PhoneParse = { ok: true; value: string } | { ok: false; error: PhoneError };

function toE164(cleaned: string): string | null {
  if (cleaned.startsWith('+')) {
    const digits = cleaned.slice(1);
    return MOBILE_E164.test(digits) ? `+${digits}` : null;
  }
  if (MOBILE_NATIONAL.test(cleaned)) {
    return `+251${cleaned.slice(1)}`; // 09/07XXXXXXXX → 9/7XXXXXXXX
  }
  if (MOBILE_SHORT.test(cleaned)) return `+251${cleaned}`;
  if (MOBILE_E164.test(cleaned)) return `+${cleaned}`;
  return null;
}

/**
 * Normalizes a phone input to E.164 or returns null when it cannot be
 * interpreted as an Ethiopian mobile number.
 */
export function normalizePhone(input: string): string | null {
  if (typeof input !== 'string') return null;
  const cleaned = input.trim().replace(/[\s\-().]/g, '');
  if (!cleaned) return null;
  return toE164(cleaned);
}

/** Like normalizePhone, but always explains WHY an input was rejected. */
export function parsePhone(input: string): PhoneParse {
  if (typeof input !== 'string' || input === '' || input.trim() === '') {
    return { ok: false, error: 'required' };
  }
  const value = normalizePhone(input);
  return value ? { ok: true, value } : { ok: false, error: 'invalidPhone' };
}

export function isValidPhone(input: string): boolean {
  return normalizePhone(input) !== null;
}

/** Human display for stored E.164 numbers: `+251912345678` → `0912345678`. */
export function formatPhoneDisplay(e164: string): string {
  return e164.startsWith('+251') ? `0${e164.slice(4)}` : e164;
}

/**
 * Best-effort normalization of a search fragment against stored E.164 numbers.
 * Returns candidate substrings that a query fragment may appear as
 * (e.g. "0912 34" → ["+25191234", "91234"]), or null when there are no digits.
 */
export function phoneSearchVariants(fragment: string): string[] | null {
  const digits = fragment.replace(/\D/g, '');
  if (!digits) return null;
  const variants = new Set<string>([digits]);
  if (digits.startsWith('09') || digits.startsWith('07')) {
    variants.add(`+251${digits.slice(1)}`);
    variants.add(digits.slice(1));
  }
  if (digits.startsWith('2519') || digits.startsWith('2517')) {
    variants.add(`+${digits}`);
  }
  return [...variants];
}
