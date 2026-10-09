/**
 * Public API/routes return ONLY whitelisted fields. This module is the single
 * place that defines the allow-lists plus a scanner used by tests to prove
 * that no private field (phone, email, notes, price, …) ever leaks.
 *
 * It is intentionally dependency-free and pure so it can be unit tested
 * without a database.
 */

/** Fields that must NEVER appear in any public response. */
export const FORBIDDEN_PUBLIC_FIELDS = [
  'phone',
  'phoneE164',
  'email',
  'notes',
  'notesNonClinical',
  'price',
  'currentPrice',
  'cost',
  'salary',
  'passwordHash',
  'annualLeaveDays',
  'reason',
  'leaveReason',
  'decisionNote',
  'ipHash',
  'patientId',
  'staffId',
  'userId',
] as const;

/** The only fields a public service may expose. */
export const PUBLIC_SERVICE_FIELDS = [
  'id',
  'name',
  'nameAm',
  'category',
  'descriptionEn',
  'descriptionAm',
] as const;

/** The only fields a public doctor may expose (matches PublicDoctor). */
export const PUBLIC_DOCTOR_FIELDS = [
  'id',
  'name',
  'specialty',
  'photoUrl',
  'status',
  'todayShifts',
  'nextShiftStartsAt',
] as const;

export type PublicService = {
  id: string;
  name: string;
  nameAm: string | null;
  category: string;
  descriptionEn: string | null;
  descriptionAm: string | null;
};

/** DB row subset selected for the public service list (never includes price). */
export type PublicServiceRow = {
  id: string;
  name: string;
  nameAm: string | null;
  category: string;
  publicDescriptionEn: string | null;
  publicDescriptionAm: string | null;
};

/**
 * Maps a DB row to the exact public shape. Only allow-listed fields are copied
 * — even if the caller passes a row with extra columns, nothing leaks.
 */
export function toPublicService(row: PublicServiceRow): PublicService {
  return {
    id: row.id,
    name: row.name,
    nameAm: row.nameAm,
    category: row.category,
    descriptionEn: row.publicDescriptionEn,
    descriptionAm: row.publicDescriptionAm,
  };
}

/**
 * Recursively collects the keys of any forbidden field found in a value.
 * Used by the privacy test to scan serialized public payloads.
 */
export function findForbiddenFields(value: unknown, found: Set<string> = new Set()): Set<string> {
  if (Array.isArray(value)) {
    for (const item of value) findForbiddenFields(item, found);
    return found;
  }
  if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      if ((FORBIDDEN_PUBLIC_FIELDS as readonly string[]).includes(key)) found.add(key);
      findForbiddenFields(child, found);
    }
  }
  return found;
}
