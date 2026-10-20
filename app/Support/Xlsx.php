<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dependency-free `.xlsx` writer, ported from src/lib/xlsx.ts.
 *
 * Produces a real XLSX (ECMA-376 OPC) file with no Composer packages: a
 * minimal ZIP archive (STORE method, no compression) wrapping the workbook
 * parts, with cell values written as inline strings and numbers. Excel,
 * LibreOffice and Numbers all open it.
 *
 * Deliberately bare-bones: single- or multi-sheet, plain columns, no styling,
 * no formulas.
 */
final class Xlsx
{
    /**
     * @param  array{name: string, headers: list<string>, rows: list<list<string|int|float|bool|null>>}|list<array{name: string, headers: list<string>, rows: list<list<string|int|float|bool|null>>}>  $worksheets
     */
    public static function toXlsx(array $worksheets): string
    {
        $sheets = array_is_list($worksheets) ? $worksheets : [$worksheets];

        if ($sheets === []) {
            throw new \InvalidArgumentException('xlsx: at least one worksheet is required');
        }

        foreach ($sheets as $sheet) {
            if ($sheet['headers'] === []) {
                throw new \InvalidArgumentException('xlsx: non-empty headers are required');
            }

            foreach ($sheet['rows'] as $row) {
                if (count($row) > count($sheet['headers'])) {
                    throw new \InvalidArgumentException('xlsx: row has more cells than headers');
                }
            }
        }

        $relTargets = [];
        foreach ($sheets as $index => $_sheet) {
            $relTargets[] = 'worksheets/sheet'.($index + 1).'.xml';
        }

        $files = [
            [
                'name' => '[Content_Types].xml',
                'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    .'<Default Extension="xml" ContentType="application/xml"/>'
                    .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    .implode('', array_map(
                        static fn (string $target): string => '<Override PartName="/xl/'.$target.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                        $relTargets,
                    ))
                    .'</Types>',
            ],
            [
                'name' => '_rels/.rels',
                'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                    .'</Relationships>',
            ],
            [
                'name' => 'xl/workbook.xml',
                'content' => self::workbookXml($sheets),
            ],
            [
                'name' => 'xl/_rels/workbook.xml.rels',
                'content' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .implode('', array_map(
                        static fn (string $target, int $index): string => '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="'.$target.'"/>',
                        $relTargets,
                        array_keys($relTargets),
                    ))
                    .'</Relationships>',
            ],
        ];

        foreach ($sheets as $index => $sheet) {
            $files[] = [
                'name' => 'xl/worksheets/sheet'.($index + 1).'.xml',
                'content' => self::sheetXml($sheet),
            ];
        }

        return self::zip($files);
    }

    /**
     * @param  list<array{name: string, headers: list<string>, rows: list<list<string|int|float|bool|null>>}>  $sheets
     */
    private static function workbookXml(array $sheets): string
    {
        $sheetsXml = '';
        foreach ($sheets as $index => $sheet) {
            $sheetsXml .= '<sheet name="'.self::xmlEscape(self::sanitizeSheetName($sheet['name'])).'"'
                .' sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetsXml.'</sheets>'
            .'</workbook>';
    }

    /** @param array{name: string, headers: list<string>, rows: list<list<string|int|float|bool|null>>} $sheet */
    private static function sheetXml(array $sheet): string
    {
        $colCount = max(1, count($sheet['headers']));
        $headerRow = self::rowXml($sheet['headers']);

        $body = '';
        foreach ($sheet['rows'] as $row) {
            $body .= self::rowXml($row);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols>'.str_repeat('<col/>', $colCount).'</cols>'
            .'<sheetData>'.$headerRow.$body.'</sheetData>'
            .'</worksheet>';
    }

    /** @param list<string|int|float|bool|null> $row */
    private static function rowXml(array $row): string
    {
        return '<row>'.implode('', array_map(self::cellXml(...), $row)).'</row>';
    }

    private static function cellXml(string|int|float|bool|null $value): string
    {
        if (is_int($value) || is_float($value)) {
            // bool before int is not an issue here (strict types keep them apart).
            $num = is_finite((float) $value) ? $value : 0;

            return '<c t="n"><v>'.$num.'</v></c>';
        }

        if (is_bool($value)) {
            return '<c t="b"><v>'.($value ? 1 : 0).'</v></c>';
        }

        $text = $value ?? '';
        if ($text === '') {
            return '<c/>';
        }

        return '<c t="inlineStr"><is><t xml:space="preserve">'.self::xmlEscape($text).'</t></is></c>';
    }

    /** >31 chars or invalid chars would corrupt the workbook — truncate + clean. */
    private static function sanitizeSheetName(string $name): string
    {
        $cleaned = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name) ?? $name, 0, 31, 'UTF-8');
        $cleaned = trim($cleaned);

        return $cleaned === '' ? 'Sheet' : $cleaned;
    }

    private static function xmlEscape(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value,
        );
    }

    /**
     * Build a ZIP archive using the STORE method (no compression), matching the
     * legacy writer byte-for-byte in structure.
     *
     * @param  list<array{name: string, content: string}>  $files
     */
    private static function zip(array $files): string
    {
        $chunks = '';
        $central = '';
        $entries = [];
        $offset = 0;

        foreach ($files as $file) {
            $name = $file['name'];
            $content = $file['content'];
            $crc = crc32($content);
            $size = strlen($content);
            $nameLen = strlen($name);

            $local = pack('V', 0x04034B50)      // local file header signature
                .pack('v', 20)                  // version needed
                .pack('v', 0)                   // flags
                .pack('v', 0)                   // method: STORE
                .pack('v', 0)                   // mod time
                .pack('v', 0)                   // mod date
                .pack('V', $crc)
                .pack('V', $size)               // compressed size (= size)
                .pack('V', $size)               // uncompressed size
                .pack('v', $nameLen)
                .pack('v', 0)                   // extra length
                .$name.$content;

            $chunks .= $local;
            $entries[] = ['name' => $name, 'offset' => $offset, 'crc' => $crc, 'size' => $size];
            $offset += strlen($local);
        }

        $centralStart = $offset;
        $centralSize = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $nameLen = strlen($name);

            $central .= pack('V', 0x02014B50)   // central dir signature
                .pack('v', 20)                  // version made by
                .pack('v', 20)                  // version needed
                .pack('v', 0)                   // flags
                .pack('v', 0)                   // method
                .pack('v', 0)                   // mod time
                .pack('v', 0)                   // mod date
                .pack('V', $entry['crc'])
                .pack('V', $entry['size'])
                .pack('V', $entry['size'])
                .pack('v', $nameLen)
                .pack('v', 0)                   // extra
                .pack('v', 0)                   // comment
                .pack('v', 0)                   // disk
                .pack('v', 0)                   // internal attrs
                .pack('V', 0)                   // external attrs
                .pack('V', $entry['offset'])
                .$name;

            $centralSize += 46 + $nameLen;
        }

        $ending = pack('V', 0x06054B50)         // EOCD signature
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', count($entries))
            .pack('v', count($entries))
            .pack('V', $centralSize)
            .pack('V', $centralStart)
            .pack('v', 0);                      // comment length

        return $chunks.$central.$ending;
    }
}
