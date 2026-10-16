import { describe, expect, it } from 'vitest';
import { MAX_IMPORT_ROWS, parseImport, type ImportContext } from './import';

function ctx(overrides: Partial<ImportContext> = {}): ImportContext {
  return { defaultLanguage: 'en', existingPhones: new Set<string>(), ...overrides };
}

function csv(...rows: string[]): string {
  return rows.join('\n');
}

const HEADER = 'fullName,phone,sex,birthDate,tags,preferredLanguage';

describe('parseImport — structure', () => {
  it('reports missing required columns', () => {
    const result = parseImport('fullName,sex\nAlmaz,FEMALE', ctx());
    expect(result.missingColumns).toEqual(['phone']);
    expect(result.rows).toHaveLength(0);
  });

  it('returns no rows for a header-only file', () => {
    const result = parseImport(HEADER, ctx());
    expect(result.missingColumns).toEqual([]);
    expect(result.rows).toHaveLength(0);
    expect(result.validCount).toBe(0);
  });

  it('flags files above the row cap', () => {
    const rows = Array.from(
      { length: MAX_IMPORT_ROWS + 1 },
      (_, i) => `Patient ${i},0911${String(i).padStart(7, '0')}`,
    );
    const result = parseImport(csv(HEADER, ...rows), ctx());
    expect(result.tooManyRows).toBe(true);
    expect(result.rows).toHaveLength(0);
  });

  it('handles BOM, quoted cells and CRLF line endings', () => {
    const text = `﻿${HEADER}\r\n"Almaz, Tesfaye",0911223344,FEMALE,1985-03-12,"glasses; follow-up",en\r\n`;
    const result = parseImport(text, ctx());
    expect(result.rows).toHaveLength(1);
    expect(result.rows[0].values?.fullName).toBe('Almaz, Tesfaye');
    expect(result.rows[0].values?.tags).toEqual(['glasses', 'follow-up']);
  });
});

describe('parseImport — phone normalization', () => {
  const phones = [
    ['0911223344', '+251911223344'],
    ['911223344', '+251911223344'],
    ['+251911223344', '+251911223344'],
    ['09 11 22 33 44', '+251911223344'],
    ['0911-223-344', '+251911223344'],
    ['0711223344', '+251711223344'],
    ['+251711223344', '+251711223344'],
  ] as const;

  it.each(phones)('normalizes %s', (raw, expected) => {
    const result = parseImport(csv(HEADER, `Almaz,${raw}`), ctx());
    expect(result.rows[0].errors).toEqual([]);
    expect(result.rows[0].values?.phoneE164).toBe(expected);
  });

  it('rejects invalid phones with a per-row error', () => {
    const result = parseImport(csv(HEADER, 'Almaz,12345', 'Bekele,09112'), ctx());
    expect(result.validCount).toBe(0);
    expect(result.errorCount).toBe(2);
    expect(result.rows[0].errors).toContainEqual({ field: 'phone', key: 'invalidPhone' });
    expect(result.rows[1].errors).toContainEqual({ field: 'phone', key: 'invalidPhone' });
  });
});

describe('parseImport — duplicates', () => {
  it('marks a phone that already exists in the database', () => {
    const existing = new Set(['+251911223344']);
    const result = parseImport(csv(HEADER, 'Almaz,0911223344'), ctx({ existingPhones: existing }));
    expect(result.rows[0].errors).toContainEqual({ field: 'phone', key: 'phoneTaken' });
    expect(result.rows[0].values).toBeNull();
  });

  it('marks a phone repeated inside the same file (second row only)', () => {
    const result = parseImport(csv(HEADER, 'Almaz,0911223344', 'Duplicate,911223344'), ctx());
    expect(result.rows[0].errors).toEqual([]);
    expect(result.rows[1].errors).toContainEqual({ field: 'phone', key: 'phoneTaken' });
    expect(result.validCount).toBe(1);
    expect(result.errorCount).toBe(1);
  });
});

describe('parseImport — field validation', () => {
  it('requires a name', () => {
    const result = parseImport(csv(HEADER, ',0911223344'), ctx());
    expect(result.rows[0].errors).toContainEqual({ field: 'fullName', key: 'required' });
  });

  it('rejects bad sex, dates and language', () => {
    const result = parseImport(csv(HEADER, 'Almaz,0911223344,X,1985-03-12,,fr'), ctx());
    const keys = result.rows[0].errors.map((error) => error.key);
    expect(keys).toContain('invalidSex');
    expect(keys).toContain('invalidLanguage');
    expect(result.rows[0].errors).toContainEqual({
      field: 'preferredLanguage',
      key: 'invalidLanguage',
    });
  });

  it('rejects future and malformed birth dates', () => {
    const future = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
    const result = parseImport(
      csv(
        HEADER,
        `A,0911223341,,,,,`,
        `B,0911223342,MALE,${future}`,
        `C,0911223343,MALE,12/31/1990`,
      ),
      ctx(),
    );
    expect(result.rows[0].errors).toHaveLength(0);
    expect(result.rows[1].errors).toContainEqual({ field: 'birthDate', key: 'invalidDate' });
    expect(result.rows[2].errors).toContainEqual({ field: 'birthDate', key: 'invalidDate' });
  });

  it('applies the default language when the column is empty', () => {
    const result = parseImport(csv(HEADER, 'Almaz,0911223344'), ctx({ defaultLanguage: 'am' }));
    expect(result.rows[0].values?.preferredLanguage).toBe('am');
  });

  it('validates only the failing rows and keeps the good ones importable', () => {
    const result = parseImport(
      csv(
        HEADER,
        'Almaz,0911223344,FEMALE,1985-03-12,glasses,en',
        ',0911223345',
        'Bekele,not-a-phone',
        'Tigist,0911223346,FEMALE,1992-07-25,,am',
      ),
      ctx(),
    );
    expect(result.rows.map((row) => row.rowNumber)).toEqual([2, 3, 4, 5]);
    expect(result.validCount).toBe(2);
    expect(result.errorCount).toBe(2);
    expect(result.rows[0].values).not.toBeNull();
    expect(result.rows[1].values).toBeNull();
    expect(result.rows[2].values).toBeNull();
    expect(result.rows[3].values).not.toBeNull();
  });
});
