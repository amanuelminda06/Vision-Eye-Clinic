import type { ZodType } from 'zod';

/**
 * Server-side validation (rule 6).
 *
 * Zod schemas carry i18n KEYS as messages (e.g. 'required', 'invalidPhone'),
 * so field errors returned to the client are translated with
 * `t('validation.' + key)` — no hard-coded UI strings (rule 10).
 */

export type FieldErrors = Record<string, string>;

export class ValidationError extends Error {
  readonly fields: FieldErrors;
  constructor(fields: FieldErrors) {
    super('VALIDATION_FAILED');
    this.name = 'ValidationError';
    this.fields = fields;
  }
}

export function validate<T>(schema: ZodType<T>, data: unknown): T {
  const result = schema.safeParse(data);
  if (result.success) return result.data;

  const fields: FieldErrors = {};
  for (const issue of result.error.issues) {
    const key = issue.path.map(String).join('.') || '_';
    if (!fields[key]) fields[key] = String(issue.message);
  }
  throw new ValidationError(fields);
}
