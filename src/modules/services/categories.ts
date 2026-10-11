/**
 * Fixed service categories. Stored as stable keys on `Service.category` (a
 * string column so new keys never need a migration) and rendered with the
 * translations below.
 */
export const SERVICE_CATEGORIES = [
  'consultation',
  'refraction',
  'diagnostics',
  'tests',
  'glasses',
  'optical',
  'contact_lenses',
  'specialist',
  'surgery',
  'other',
] as const;

export type ServiceCategory = (typeof SERVICE_CATEGORIES)[number];

export function isServiceCategory(value: unknown): value is ServiceCategory {
  return typeof value === 'string' && (SERVICE_CATEGORIES as readonly string[]).includes(value);
}

/** `t()` message key inside the `services` namespace for each category. */
export const CATEGORY_LABEL_KEYS: Record<ServiceCategory, string> = {
  consultation: 'catConsultation',
  refraction: 'catRefraction',
  diagnostics: 'catDiagnostics',
  tests: 'catTests',
  glasses: 'catGlasses',
  optical: 'catOptical',
  contact_lenses: 'catContactLenses',
  specialist: 'catSpecialist',
  surgery: 'catSurgery',
  other: 'catOther',
};
