/**
 * Dependency-free `.xlsx` writer.
 *
 * Produces a real XLSX (ECMA-376 OPC) file with no npm packages: a minimal
 * ZIP archive (STORE method, no compression) wrapping the workbook parts, with
 * cell values written as inline strings and numbers. Excel, LibreOffice and
 * Numbers all open it.
 *
 * Deliberately bare-bones: single workbook, plain columns, no styling, no
 * formulas. Good enough for exports where the number/formula work happens in
 * the spreadsheet app.
 */

export type XlsxCell = string | number | boolean | null | undefined;
export type XlsxRow = XlsxCell[];
export type XlsxWorksheet = {
  name: string;
  headers: string[];
  rows: XlsxRow[];
};

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

function crc32(buffer: Uint8Array): number {
  let crc = 0xffffffff;
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

/** Escape text for XML element/attribute content. */
function xmlEscape(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

function cellXml(value: XlsxCell): string {
  if (typeof value === 'number') {
    return `<c t="n"><v>${Number.isFinite(value) ? value : 0}</v></c>`;
  }
  if (typeof value === 'boolean') {
    return `<c t="b"><v>${value ? 1 : 0}</v></c>`;
  }
  const text = value == null ? '' : String(value);
  if (text === '') return '<c/>';
  return `<c t="inlineStr"><is><t xml:space="preserve">${xmlEscape(text)}</t></is></c>`;
}

function rowXml(row: XlsxRow): string {
  return `<row>${row.map(cellXml).join('')}</row>`;
}

function sheetXml(sheet: XlsxWorksheet): string {
  const colCount = Math.max(1, sheet.headers.length);
  const headerRow = rowXml([...sheet.headers]);
  const body = sheet.rows.map(rowXml).join('');
  return (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
    `<cols>${Array.from({ length: colCount }, () => '<col/>').join('')}</cols>` +
    `<sheetData>${headerRow}${body}</sheetData>` +
    '</worksheet>'
  );
}

function workbookXml(sheets: XlsxWorksheet[]): string {
  const sheetsXml = sheets
    .map(
      (sheet, index) =>
        `<sheet name="${xmlEscape(sanitizeSheetName(sheet.name))}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`,
    )
    .join('');
  return (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' +
    'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
    '<sheets>' +
    sheetsXml +
    '</sheets>' +
    '</workbook>'
  );
}

/** >31 chars or invalid chars would corrupt the workbook — truncate + clean. */
function sanitizeSheetName(name: string): string {
  const cleaned = name.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31);
  return cleaned.trim() || 'Sheet';
}

/** Build the ZIP (STORE method). Returns [parts, offsets] for central dir. */
function zip(files: Array<{ name: string; content: Uint8Array }>): Uint8Array {
  const encoder = new TextEncoder();
  const chunks: Uint8Array[] = [];
  const entries: Array<{ name: string; offset: number; crc: number; size: number }> = [];
  let offset = 0;

  for (const file of files) {
    const nameBytes = encoder.encode(file.name);
    const crc = crc32(file.content);
    entries.push({ name: file.name, offset, crc, size: file.content.length });

    const local = new Uint8Array(nameBytes.length + file.content.length + 30);
    const view = new DataView(local.buffer);
    view.setUint32(0, 0x04034b50, true); // local file header signature
    view.setUint16(4, 20, true); // version needed
    view.setUint16(6, 0, true); // flags
    view.setUint16(8, 0, true); // method: STORE
    view.setUint16(10, 0, true); // mod time
    view.setUint16(12, 0, true); // mod date
    view.setUint32(14, crc, true);
    view.setUint32(18, file.content.length, true); // compressed size (= size)
    view.setUint32(22, file.content.length, true); // uncompressed size
    view.setUint16(26, nameBytes.length, true);
    view.setUint16(28, 0, true); // extra length
    local.set(nameBytes, 30);
    local.set(file.content, 30 + nameBytes.length);
    chunks.push(local);
    offset += local.length;
  }

  const encoder2 = new TextEncoder();
  let centralSize = 0;
  for (const entry of entries) {
    const nameBytes = encoder2.encode(entry.name);
    centralSize += 46 + nameBytes.length;
  }
  const centralStart = offset;
  const central = new Uint8Array(centralSize);
  const csv = new DataView(central.buffer);
  let cursor = 0;
  for (const entry of entries) {
    const nameBytes = encoder2.encode(entry.name);
    csv.setUint32(cursor, 0x02014b50, true); // central dir signature
    csv.setUint16(cursor + 4, 20, true); // version made by
    csv.setUint16(cursor + 6, 20, true); // version needed
    csv.setUint16(cursor + 8, 0, true); // flags
    csv.setUint16(cursor + 10, 0, true); // method
    csv.setUint16(cursor + 12, 0, true); // mod time
    csv.setUint16(cursor + 14, 0, true); // mod date
    csv.setUint32(cursor + 16, entry.crc, true);
    csv.setUint32(cursor + 20, entry.size, true);
    csv.setUint32(cursor + 24, entry.size, true);
    csv.setUint16(cursor + 28, nameBytes.length, true);
    csv.setUint16(cursor + 30, 0, true); // extra
    csv.setUint16(cursor + 32, 0, true); // comment
    csv.setUint16(cursor + 34, 0, true); // disk
    csv.setUint16(cursor + 36, 0, true); // internal attrs
    csv.setUint32(cursor + 38, 0, true); // external attrs
    csv.setUint32(cursor + 42, entry.offset, true);
    central.set(nameBytes, cursor + 46);
    cursor += 46 + nameBytes.length;
  }

  const ending = new Uint8Array(22);
  const ev = new DataView(ending.buffer);
  ev.setUint32(0, 0x06054b50, true); // EOCD signature
  ev.setUint16(4, 0, true);
  ev.setUint16(6, 0, true);
  ev.setUint16(8, entries.length, true);
  ev.setUint16(10, entries.length, true);
  ev.setUint32(12, centralSize, true);
  ev.setUint32(16, centralStart, true);
  ev.setUint16(20, 0, true); // comment length

  const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0) + central.length + ending.length;
  const out = new Uint8Array(total);
  let pos = 0;
  for (const chunk of chunks) {
    out.set(chunk, pos);
    pos += chunk.length;
  }
  out.set(central, pos);
  pos += central.length;
  out.set(ending, pos);
  return out;
}

/** Serialize worksheets into a standalone `.xlsx` Buffer. */
export function toXlsx(worksheets: XlsxWorksheet[] | XlsxWorksheet): Buffer {
  const sheets: XlsxWorksheet[] = Array.isArray(worksheets)
    ? worksheets
    : [worksheets];
  if (sheets.length === 0) throw new Error('xlsx: at least one worksheet is required');
  for (const sheet of sheets) {
    if (sheet.headers.length === 0) throw new Error('xlsx: non-empty headers are required');
    for (const row of sheet.rows) {
      if (row.length > sheet.headers.length) {
        throw new Error('xlsx: row has more cells than headers');
      }
    }
  }

  const relTargets = sheets.map((_sheet, index) => `worksheets/sheet${index + 1}.xml`);
  const encoder = new TextEncoder();
  const files = [
    {
      name: '[Content_Types].xml',
      content: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
          '<Default Extension="xml" ContentType="application/xml"/>' +
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
          relTargets
            .map(
              (target) =>
                `<Override PartName="/xl/${target}" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`,
            )
            .join('') +
          '</Types>',
      ),
    },
    {
      name: '_rels/.rels',
      content: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
          '</Relationships>',
      ),
    },
    {
      name: 'xl/workbook.xml',
      content: encoder.encode(workbookXml(sheets)),
    },
    {
      name: 'xl/_rels/workbook.xml.rels',
      content: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          relTargets
            .map(
              (target, index) =>
                `<Relationship Id="rId${index + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="${target}"/>`,
            )
            .join('') +
          '</Relationships>',
      ),
    },
    ...sheets.map((sheet, index) => ({
      name: `xl/worksheets/sheet${index + 1}.xml`,
      content: encoder.encode(sheetXml(sheet)),
    })),
  ];

  return Buffer.from(zip(files));
}