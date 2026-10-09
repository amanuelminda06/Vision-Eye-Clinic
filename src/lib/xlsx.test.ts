import { describe, expect, it } from 'vitest';
import { toXlsx } from './xlsx';

/**
 * Read the STORE-method ZIP back: locate each entry across the local file
 * headers (signature 0x04034b50) and inflate the raw bytes (not compressed for
 * STORE entries, so inflateRaw returns them verbatim).
 */
function readZipEntry(buffer: Buffer, fileName: string): Buffer {
  let i = 0;
  while (i + 30 < buffer.length) {
    const sig = buffer.readUInt32LE(i);
    if (sig !== 0x04034b50) break;
    const nameLen = buffer.readUInt16LE(i + 26);
    const extraLen = buffer.readUInt16LE(i + 28);
    const size = buffer.readUInt32LE(i + 22);
    const name = buffer.subarray(i + 30, i + 30 + nameLen).toString();
    const dataStart = i + 30 + nameLen + extraLen;
    if (name === fileName) return buffer.subarray(dataStart, dataStart + size);
    i = dataStart + size;
  }
  throw new Error(`entry not found: ${fileName}`);
}

// Entries are STORE (uncompressed) — the payload is the raw bytes.
function entryText(input: Buffer): string {
  return input.toString('utf8');
}

describe('toXlsx', () => {
  it('writes a valid PK zip with the required parts', () => {
    const out = toXlsx([
      {
        name: 'Sales',
        headers: ['receipt', 'amount'],
        rows: [
          [1, '2026-0001'],
          [2, 12.5],
        ],
      },
    ]);

    expect(out.subarray(0, 2).toString()).toBe('PK');
    expect(readZipEntry(out, '[Content_Types].xml').length).toBeGreaterThan(0);
    expect(readZipEntry(out, '_rels/.rels').length).toBeGreaterThan(0);
    expect(readZipEntry(out, 'xl/workbook.xml').length).toBeGreaterThan(0);
    expect(readZipEntry(out, 'xl/_rels/workbook.xml.rels').length).toBeGreaterThan(0);
    expect(readZipEntry(out, 'xl/worksheets/sheet1.xml').length).toBeGreaterThan(0);
  });

  it('escapes XML and handles empty cells', () => {
    const out = toXlsx({
      name: 'Sheet 1',
      headers: ['a', 'b'],
      rows: [['<evil & "quoted">', null], [undefined, true]],
    });
    const sheet = entryText(readZipEntry(out, 'xl/worksheets/sheet1.xml'));
    expect(sheet).toContain('&lt;evil &amp; &quot;quoted&quot;&gt;');
    expect(sheet).toContain('<c/>');
    expect(sheet).toContain('<c t="b"><v>1</v></c>');
  });

  it('sanitizes invalid sheet names and caps their length', () => {
    const out = toXlsx({
      name: 'bad/name:with:many[chars]and?more*stuff',
      headers: ['x'],
      rows: [],
    });
    const workbook = entryText(readZipEntry(out, 'xl/workbook.xml'));
    const expected = 'bad name with many chars and mo'; // invalid chars → space, sliced to 31
    expect(workbook).toContain(`<sheet name="${expected}"`);
    expect(workbook).not.toContain('[/?:*');
  });

  it('throws on empty worksheets or oversize rows', () => {
    expect(() => toXlsx([])).toThrow();
    expect(() => toXlsx({ name: 's', headers: [], rows: [] })).toThrow();
    expect(() =>
      toXlsx({ name: 's', headers: ['a'], rows: [['a', 'b']] }),
    ).toThrow();
  });
});