<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Xlsx;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/xlsx.test.ts — the legacy gold standard for the
 * dependency-free XLSX writer. Entries are STORE (uncompressed), so the raw
 * bytes of each ZIP part are read back verbatim.
 */
class XlsxTest extends TestCase
{
    /** Find a ZIP entry by name across the local file headers. */
    private function readZipEntry(string $buffer, string $fileName): string
    {
        $i = 0;
        $length = strlen($buffer);

        while ($i + 30 < $length) {
            $signature = unpack('V', substr($buffer, $i, 4))[1];
            if ($signature !== 0x04034B50) {
                break;
            }

            $size = unpack('V', substr($buffer, $i + 22, 4))[1];
            $nameLen = unpack('v', substr($buffer, $i + 26, 2))[1];
            $extraLen = unpack('v', substr($buffer, $i + 28, 2))[1];
            $name = substr($buffer, $i + 30, $nameLen);
            $dataStart = $i + 30 + $nameLen + $extraLen;

            if ($name === $fileName) {
                return substr($buffer, $dataStart, $size);
            }

            $i = $dataStart + $size;
        }

        $this->fail("entry not found: {$fileName}");
    }

    public function test_writes_a_valid_pk_zip_with_the_required_parts(): void
    {
        $out = Xlsx::toXlsx([
            'name' => 'Sales',
            'headers' => ['receipt', 'amount'],
            'rows' => [[1, '2026-0001'], [2, 12.5]],
        ]);

        $this->assertSame('PK', substr($out, 0, 2));

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ] as $entry) {
            $this->assertNotSame('', $this->readZipEntry($out, $entry));
        }
    }

    public function test_escapes_xml_and_handles_empty_cells(): void
    {
        $out = Xlsx::toXlsx([
            'name' => 'Sheet 1',
            'headers' => ['a', 'b'],
            'rows' => [['<evil & "quoted">', null], [null, true]],
        ]);

        $sheet = $this->readZipEntry($out, 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('&lt;evil &amp; &quot;quoted&quot;&gt;', $sheet);
        $this->assertStringContainsString('<c/>', $sheet);
        $this->assertStringContainsString('<c t="b"><v>1</v></c>', $sheet);
    }

    public function test_writes_numbers_with_the_number_type(): void
    {
        $out = Xlsx::toXlsx(['name' => 'N', 'headers' => ['n'], 'rows' => [[42], [1.5]]]);
        $sheet = $this->readZipEntry($out, 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<c t="n"><v>42</v></c>', $sheet);
        $this->assertStringContainsString('<c t="n"><v>1.5</v></c>', $sheet);
    }

    public function test_sanitizes_invalid_sheet_names_and_caps_their_length(): void
    {
        $out = Xlsx::toXlsx([
            'name' => 'bad/name:with:many[chars]and?more*stuff',
            'headers' => ['x'],
            'rows' => [],
        ]);

        $workbook = $this->readZipEntry($out, 'xl/workbook.xml');

        // invalid chars → space, sliced to 31 chars
        $this->assertStringContainsString('<sheet name="bad name with many chars and mo"', $workbook);
        $this->assertStringNotContainsString('[/?:*', $workbook);
    }

    public function test_supports_multiple_worksheets(): void
    {
        $out = Xlsx::toXlsx([
            ['name' => 'One', 'headers' => ['a'], 'rows' => [['x']]],
            ['name' => 'Two', 'headers' => ['b'], 'rows' => [['y']]],
        ]);

        $this->assertNotSame('', $this->readZipEntry($out, 'xl/worksheets/sheet1.xml'));
        $this->assertNotSame('', $this->readZipEntry($out, 'xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('rId2"', $this->readZipEntry($out, 'xl/workbook.xml'));
    }

    public function test_throws_on_empty_worksheets_or_oversize_rows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Xlsx::toXlsx([]);
    }

    public function test_throws_on_empty_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Xlsx::toXlsx(['name' => 's', 'headers' => [], 'rows' => []]);
    }

    public function test_throws_when_a_row_is_wider_than_the_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Xlsx::toXlsx(['name' => 's', 'headers' => ['a'], 'rows' => [['a', 'b']]]);
    }
}
