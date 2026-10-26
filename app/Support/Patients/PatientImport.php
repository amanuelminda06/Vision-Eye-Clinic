<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Support\Phone;

/**
 * Pure CSV-import validation for patients, ported 1:1 from
 * src/modules/patients/import.ts. Rows are validated in isolation; nothing is
 * written to the database here.
 */
final class PatientImport
{
    /** @var list<string> */
    public const IMPORT_COLUMNS = [
        'fullName',
        'phone',
        'sex',
        'age',
        'tags',
        'preferredLanguage',
    ];

    /** @var list<string> */
    public const IMPORT_REQUIRED_COLUMNS = ['fullName', 'phone'];

    public const MAX_IMPORT_ROWS = 1000;

    public const MAX_IMPORT_BYTES = 2 * 1024 * 1024;

    public const MAX_TAG_LENGTH = 30;

    public const MAX_TAGS_PER_PATIENT = 10;

    /** English messages for the downloadable error report (file artifact, not UI). */
    public const IMPORT_ERROR_MESSAGES = [
        'required' => 'Required',
        'tooLong' => 'Too long',
        'invalid' => 'Invalid value',
        'invalidPhone' => 'Invalid phone number',
        'phoneTaken' => 'Phone number already exists',
        'invalidSex' => 'Invalid sex — use Male/Female/Other (or M/F/O)',
        'invalidAge' => 'Invalid age — use a whole number between 0 and 120',
        'invalidLanguage' => 'Invalid language — use en or am',
        'fileTooLarge' => 'File is larger than 2 MB',
        'tooManyRows' => 'More than 1000 rows',
        'missingColumns' => 'Missing required column',
    ];

    /** @var array<string, string> */
    private const SEX_ALIASES = [
        'm' => 'MALE',
        'male' => 'MALE',
        'f' => 'FEMALE',
        'female' => 'FEMALE',
        'o' => 'OTHER',
        'other' => 'OTHER',
    ];

    /**
     * Validate one raw row; never returns `values` for a row with errors.
     *
     * @param  array<string, string>  $raw
     * @param  array{defaultLanguage: string, existingPhones: list<string>, seenPhones?: array<string, true>}  $ctx
     * @return array{rowNumber: int, raw: array<string, string>, errors: list<array{field: string, key: string}>, values: array<string, mixed>|null}
     */
    public static function validateImportRow(array $raw, array $ctx): array
    {
        $errors = [];
        $seen = $ctx['seenPhones'] ?? [];

        $fullName = trim($raw['fullName'] ?? '');
        if ($fullName === '') {
            $errors[] = ['field' => 'fullName', 'key' => 'required'];
        } elseif (mb_strlen($fullName, 'UTF-8') > 120) {
            $errors[] = ['field' => 'fullName', 'key' => 'tooLong'];
        }

        $phoneInput = trim($raw['phone'] ?? '');
        $phone = Phone::parse($phoneInput);
        $phoneE164 = null;

        if ($phone['ok'] !== true) {
            $errors[] = ['field' => 'phone', 'key' => $phone['error']];
        } else {
            $phoneE164 = $phone['value'];

            if (in_array($phoneE164, $ctx['existingPhones'], true) || isset($seen[$phoneE164])) {
                $errors[] = ['field' => 'phone', 'key' => 'phoneTaken'];
            } else {
                $seen[$phoneE164] = true;
            }
        }

        $sex = self::parseSexCell($raw['sex'] ?? '');
        if ($sex === false) {
            $errors[] = ['field' => 'sex', 'key' => 'invalidSex'];
        }

        $age = self::parseAgeCell($raw['age'] ?? '');
        if ($age === false) {
            $errors[] = ['field' => 'age', 'key' => 'invalidAge'];
        }

        $tags = self::parseTagsCell($raw['tags'] ?? '');
        if ($tags === false) {
            $errors[] = ['field' => 'tags', 'key' => 'invalid'];
        }

        $language = self::parseLanguageCell($raw['preferredLanguage'] ?? '', $ctx['defaultLanguage']);
        if ($language === false) {
            $errors[] = ['field' => 'preferredLanguage', 'key' => 'invalidLanguage'];
        }

        $values = null;

        if ($errors === [] && $phoneE164 !== null) {
            $values = [
                'fullName' => $fullName,
                'phoneE164' => $phoneE164,
                'sex' => $sex === false ? null : $sex,
                'age' => $age === false ? null : $age,
                'tags' => $tags === false ? [] : $tags,
                'preferredLanguage' => $language === false ? $ctx['defaultLanguage'] : $language,
            ];
        }

        return [
            'rowNumber' => 0,
            'raw' => $raw,
            'errors' => $errors,
            'values' => $values,
            'seenPhones' => $seen,
        ];
    }

    /**
     * Parse and validate a whole CSV file (header row required).
     *
     * @param  array{defaultLanguage: string, existingPhones: list<string>}  $ctx
     * @return array{missingColumns: list<string>, tooManyRows: bool, rows: list<array<string, mixed>>, validCount: int, errorCount: int}
     */
    public static function parseImport(string $text, array $ctx): array
    {
        $parsed = Csv::parseCsv($text);

        if ($parsed === []) {
            return [
                'missingColumns' => self::IMPORT_REQUIRED_COLUMNS,
                'tooManyRows' => false,
                'rows' => [],
                'validCount' => 0,
                'errorCount' => 0,
            ];
        }

        $header = array_map(trim(...), $parsed[0]);
        $missingColumns = array_values(array_filter(
            self::IMPORT_REQUIRED_COLUMNS,
            static fn (string $column): bool => ! in_array($column, $header, true),
        ));

        if ($missingColumns !== []) {
            return [
                'missingColumns' => $missingColumns,
                'tooManyRows' => false,
                'rows' => [],
                'validCount' => 0,
                'errorCount' => 0,
            ];
        }

        $dataRows = array_slice($parsed, 1);
        $tooManyRows = count($dataRows) > self::MAX_IMPORT_ROWS;

        if ($tooManyRows) {
            return [
                'missingColumns' => [],
                'tooManyRows' => true,
                'rows' => [],
                'validCount' => 0,
                'errorCount' => 0,
            ];
        }

        $seenPhones = [];
        $rows = [];
        $rowNumber = 2; // header is row 1

        foreach ($dataRows as $cells) {
            $raw = [];
            foreach ($header as $index => $column) {
                $raw[$column] = trim($cells[$index] ?? '');
            }

            $rowCtx = $ctx;
            $rowCtx['seenPhones'] = $seenPhones;

            $row = self::validateImportRow($raw, $rowCtx);
            $seenPhones = $row['seenPhones'];
            unset($row['seenPhones']);

            $row['rowNumber'] = $rowNumber;
            $rows[] = $row;
            $rowNumber++;
        }

        $validCount = count(array_filter($rows, static fn (array $row): bool => $row['values'] !== null));

        return [
            'missingColumns' => [],
            'tooManyRows' => false,
            'rows' => $rows,
            'validCount' => $validCount,
            'errorCount' => count($rows) - $validCount,
        ];
    }

    /** @return string|null|false MALE|FEMALE|OTHER, null for empty, false when invalid. */
    private static function parseSexCell(string $raw): string|null|false
    {
        $key = mb_strtolower(trim($raw), 'UTF-8');

        if ($key === '') {
            return null;
        }

        return self::SEX_ALIASES[$key] ?? false;
    }

    /** @return int|null|false Whole years, null for empty, false when invalid. */
    private static function parseAgeCell(string $raw): int|null|false
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return false;
        }

        $age = (int) $value;

        return $age >= PatientValidation::MIN_AGE && $age <= PatientValidation::MAX_AGE ? $age : false;
    }

    /** @return list<string>|false */
    private static function parseTagsCell(string $raw): array|false
    {
        $parts = [];
        foreach (preg_split('/[;,|]/', $raw) ?: [] as $part) {
            $part = mb_strtolower(trim($part), 'UTF-8');
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') > self::MAX_TAG_LENGTH) {
                return false;
            }
        }

        if (count($parts) > self::MAX_TAGS_PER_PATIENT) {
            return false;
        }

        return array_values(array_unique($parts));
    }

    private static function parseLanguageCell(string $raw, string $fallback): string|false
    {
        $key = mb_strtolower(trim($raw), 'UTF-8');

        if ($key === '') {
            return $fallback;
        }

        if ($key === 'en' || $key === 'am') {
            return $key;
        }

        return false;
    }
}
