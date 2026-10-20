<?php

declare(strict_types=1);

namespace App\Support\Patients;

/**
 * Minimal CSV writer + RFC-4180 parser, ported from src/lib/csv.ts.
 *
 * `toCsv` prepends a UTF-8 BOM and uses CRLF line endings so Excel opens the
 * file correctly. `parseCsv` handles quoted cells, `""` escapes, CR/LF/CRLF
 * separators, strips a leading BOM, and drops rows that are entirely blank.
 */
final class Csv
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|bool|null>>  $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $lines = [implode(',', array_map(self::escapeCell(...), $headers))];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(self::escapeCell(...), $row));
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return list<list<string>>
     */
    public static function parseCsv(string $text): array
    {
        // Strip a leading UTF-8 BOM if present.
        $input = str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;

        $rows = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $ch = $input[$i];

            if ($inQuotes) {
                if ($ch === '"') {
                    if ($i + 1 < $length && $input[$i + 1] === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $cell .= $ch;
                }

                continue;
            }

            if ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $row[] = $cell;
                $cell = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && $i + 1 < $length && $input[$i + 1] === "\n") {
                    $i++;
                }

                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
            } else {
                $cell .= $ch;
            }
        }

        if ($cell !== '' || $row !== []) {
            $row[] = $cell;
            $rows[] = $row;
        }

        // Drop rows that are entirely blank once trimmed.
        return array_values(array_filter($rows, static function (array $r): bool {
            foreach ($r as $value) {
                if (trim($value) !== '') {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function escapeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $str = $value ? 'true' : 'false';
        } else {
            $str = (string) $value;
        }

        if (preg_match('/[",\n\r]/', $str) === 1) {
            return '"'.str_replace('"', '""', $str).'"';
        }

        return $str;
    }
}
