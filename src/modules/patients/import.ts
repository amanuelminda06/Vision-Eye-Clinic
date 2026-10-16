import { parseCsv } from '@/lib/csv';
import { parsePhone } from '@/lib/phone';

/**
 * Pure CSV-import validation for patients (unit-tested).
 * Rows are validated in isolation; nothing is written to the database here.
 */

export const IMPORT_COLUMNS = [
  'fullName',
  'phone',
  'sex',
  'birthDate',
  'tags',
  'preferredLanguage',
] as const;

export const IMPORT_REQUIRED_COLUMNS: string[] = ['fullName', 'phone'];

/** Hard cap so a bad upload can never wedge the server. */
export const MAX_IMPORT_ROWS = 1000;
export const MAX_IMPORT_BYTES = 2 * 1024 * 1024;
export const MAX_TAG_LENGTH = 30;
export const MAX_TAGS_PER_PATIENT = 10;

export type ImportSex = 'MALE' | 'FEMALE' | 'OTHER';

export type ImportValues = {
  fullName: string;
  phoneE164: string;
  sex: ImportSex | null;
  /** Calendar date as `YYYY-MM-DD`, or null when the cell was empty. */
  birthDate: string | null;
  tags: string[];
  preferredLanguage: 'en' | 'am';
};

export type ImportRow = {
  /** Spreadsheet row number (header is row 1). */
  rowNumber: number;
  raw: Record<string, string>;
  errors: Array<{ field: string; key: string }>;
  /** Set only when the row is fully valid (errors === []). */
  values: ImportValues | null;
};

export type ImportResult = {
  missingColumns: string[];
  tooManyRows: boolean;
  rows: ImportRow[];
  validCount: number;
  errorCount: number;
};

const SEX_ALIASES: Record<string, ImportSex> = {
  m: 'MALE',
  male: 'MALE',
  f: 'FEMALE',
  female: 'FEMALE',
  o: 'OTHER',
  other: 'OTHER',
};

/** English messages for the downloadable error report (file artifact, not UI). */
export const IMPORT_ERROR_MESSAGES: Record<string, string> = {
  required: 'Required',
  tooLong: 'Too long',
  invalid: 'Invalid value',
  invalidPhone: 'Invalid phone number',
  phoneTaken: 'Phone number already exists',
  invalidSex: 'Invalid sex — use Male/Female/Other (or M/F/O)',
  invalidDate: 'Invalid date — use YYYY-MM-DD and a date in the past',
  invalidLanguage: 'Invalid language — use en or am',
  fileTooLarge: 'File is larger than 2 MB',
  tooManyRows: `More than ${MAX_IMPORT_ROWS} rows`,
  missingColumns: 'Missing required column',
};

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function parseSexCell(raw: string): { ok: true; value: ImportSex | null } | { ok: false } {
  const key = raw.trim().toLowerCase();
  if (key === '') return { ok: true, value: null };
  const value = SEX_ALIASES[key];
  return value ? { ok: true, value } : { ok: false };
}

function parseBirthDateCell(raw: string): { ok: true; value: string | null } | { ok: false } {
  const value = raw.trim();
  if (value === '') return { ok: true, value: null };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return { ok: false };
  if (Number.isNaN(Date.parse(`${value}T00:00:00Z`))) return { ok: false };
  if (value > todayIso()) return { ok: false };
  return { ok: true, value };
}

function parseTagsCell(raw: string): { ok: true; value: string[] } | { ok: false } {
  const parts = raw
    .split(/[;,|]/)
    .map((part) => part.trim().toLowerCase())
    .filter(Boolean);
  if (parts.some((part) => part.length > MAX_TAG_LENGTH)) return { ok: false };
  if (parts.length > MAX_TAGS_PER_PATIENT) return { ok: false };
  return { ok: true, value: [...new Set(parts)] };
}

function parseLanguageCell(
  raw: string,
  fallback: 'en' | 'am',
): { ok: true; value: 'en' | 'am' } | { ok: false } {
  const key = raw.trim().toLowerCase();
  if (key === '') return { ok: true, value: fallback };
  if (key === 'en' || key === 'am') return { ok: true, value: key };
  return { ok: false };
}

export type ImportContext = {
  defaultLanguage: 'en' | 'am';
  /** Phones already present in the database. */
  existingPhones: Set<string>;
  /** Phones seen earlier in the same file (mutated as rows are validated). */
  seenPhones?: Set<string>;
};

/** Validates one raw row; never returns `values` for a row with errors. */
export function validateImportRow(raw: Record<string, string>, ctx: ImportContext): ImportRow {
  const errors: Array<{ field: string; key: string }> = [];
  const seen = ctx.seenPhones ?? new Set<string>();

  const fullName = (raw.fullName ?? '').trim();
  if (!fullName) errors.push({ field: 'fullName', key: 'required' });
  else if (fullName.length > 120) errors.push({ field: 'fullName', key: 'tooLong' });

  const phoneInput = (raw.phone ?? '').trim();
  const phone = parsePhone(phoneInput);
  let phoneE164: string | null = null;
  if (!phone.ok) {
    errors.push({ field: 'phone', key: phone.error });
  } else {
    phoneE164 = phone.value;
    if (ctx.existingPhones.has(phoneE164) || seen.has(phoneE164)) {
      errors.push({ field: 'phone', key: 'phoneTaken' });
    } else {
      seen.add(phoneE164);
    }
  }

  const sex = parseSexCell(raw.sex ?? '');
  if (!sex.ok) errors.push({ field: 'sex', key: 'invalidSex' });

  const birthDate = parseBirthDateCell(raw.birthDate ?? '');
  if (!birthDate.ok) errors.push({ field: 'birthDate', key: 'invalidDate' });

  const tags = parseTagsCell(raw.tags ?? '');
  if (!tags.ok) errors.push({ field: 'tags', key: 'invalid' });

  const language = parseLanguageCell(raw.preferredLanguage ?? '', ctx.defaultLanguage);
  if (!language.ok) errors.push({ field: 'preferredLanguage', key: 'invalidLanguage' });

  const values: ImportValues | null =
    errors.length === 0 && phoneE164 !== null
      ? {
          fullName,
          phoneE164,
          sex: sex.ok ? sex.value : null,
          birthDate: birthDate.ok ? birthDate.value : null,
          tags: tags.ok ? tags.value : [],
          preferredLanguage: language.ok ? language.value : ctx.defaultLanguage,
        }
      : null;

  return { rowNumber: 0, raw, errors, values };
}

/** Parses and validates a whole CSV file (header row required). */
export function parseImport(text: string, ctx: ImportContext): ImportResult {
  const parsed = parseCsv(text);
  if (parsed.length === 0) {
    return {
      missingColumns: [...IMPORT_REQUIRED_COLUMNS],
      tooManyRows: false,
      rows: [],
      validCount: 0,
      errorCount: 0,
    };
  }

  const header = parsed[0].map((cell) => cell.trim());
  const missingColumns = IMPORT_REQUIRED_COLUMNS.filter((column) => !header.includes(column));
  if (missingColumns.length > 0) {
    return { missingColumns, tooManyRows: false, rows: [], validCount: 0, errorCount: 0 };
  }

  const dataRows = parsed.slice(1);
  const tooManyRows = dataRows.length > MAX_IMPORT_ROWS;
  if (tooManyRows) {
    return { missingColumns: [], tooManyRows: true, rows: [], validCount: 0, errorCount: 0 };
  }

  const seenPhones = new Set<string>();
  const rows: ImportRow[] = dataRows.map((cells, index) => {
    const raw: Record<string, string> = {};
    header.forEach((column, i) => {
      raw[column] = (cells[i] ?? '').trim();
    });
    const row = validateImportRow(raw, { ...ctx, seenPhones });
    return { ...row, rowNumber: index + 2 }; // +2: header is row 1
  });

  const validCount = rows.filter((row) => row.values !== null).length;
  return {
    missingColumns: [],
    tooManyRows,
    rows,
    validCount,
    errorCount: rows.length - validCount,
  };
}
