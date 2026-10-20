<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\Csv;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/csv.ts (toCsv BOM+CRLF writer, RFC-4180 parser). The library
 * had no legacy test file; these assertions pin the documented behaviour the
 * patient import/export depends on.
 */
class CsvTest extends TestCase
{
    public function test_to_csv_prepends_a_bom_and_uses_crlf(): void
    {
        $csv = Csv::toCsv(['a', 'b'], [['1', '2']]);

        $this->assertSame("\u{FEFF}a,b\r\n1,2\r\n", $csv);
    }

    public function test_to_csv_quotes_cells_containing_separators_or_quotes(): void
    {
        $csv = Csv::toCsv(['x'], [['a,b'], ['line'."\n".'break'], ['say "hi"']]);

        $this->assertStringContainsString('"a,b"', $csv);
        $this->assertStringContainsString("\"line\nbreak\"", $csv);
        $this->assertStringContainsString('"say ""hi"""', $csv);
    }

    public function test_to_csv_renders_nulls_as_empty_and_booleans_as_words(): void
    {
        $csv = Csv::toCsv(['a', 'b', 'c'], [[null, true, false]]);

        // null → empty cell, true/false → the words the legacy String() produced.
        $this->assertSame("\u{FEFF}a,b,c\r\n,true,false\r\n", $csv);
    }

    public function test_parse_csv_strips_bom_and_splits_rows(): void
    {
        $rows = Csv::parseCsv("\u{FEFF}a,b\r\n1,2\r\n");

        $this->assertSame([['a', 'b'], ['1', '2']], $rows);
    }

    public function test_parse_csv_handles_quoted_cells_and_escaped_quotes(): void
    {
        $rows = Csv::parseCsv('"a,b","say ""hi""",plain');

        $this->assertSame([['a,b', 'say "hi"', 'plain']], $rows);
    }

    public function test_parse_csv_handles_lf_and_crlf_and_embedded_newlines(): void
    {
        $rows = Csv::parseCsv("x,\"line\nbreak\"\ny,z");

        $this->assertSame([['x', "line\nbreak"], ['y', 'z']], $rows);
    }

    public function test_parse_csv_drops_blank_rows(): void
    {
        $rows = Csv::parseCsv("a,b\n\n,\n c ,d ");

        $this->assertSame([['a', 'b'], [' c ', 'd ']], $rows);
    }

    public function test_round_trip_preserves_simple_values(): void
    {
        $original = [['Mekdes Alemu', '0912345678'], ['ሰላም ገብረ', '0712345678']];
        $csv = Csv::toCsv(['name', 'phone'], $original);

        $this->assertSame([['name', 'phone'], ...$original], Csv::parseCsv($csv));
    }
}
