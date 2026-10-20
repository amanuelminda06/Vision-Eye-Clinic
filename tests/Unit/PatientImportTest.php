<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\PatientImport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/modules/patients/import.test.ts — the legacy gold standard for
 * pure CSV-import validation (structure, phone normalization, duplicates,
 * per-field rules, partial import).
 */
class PatientImportTest extends TestCase
{
    private const HEADER = 'fullName,phone,sex,birthDate,tags,preferredLanguage';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'defaultLanguage' => 'en',
            'existingPhones' => [],
        ], $overrides);
    }

    private function csv(string ...$rows): string
    {
        return implode("\n", $rows);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function errorKeys(array $row): array
    {
        return array_map(static fn (array $e): string => $e['key'], $row['errors']);
    }

    public function test_reports_missing_required_columns(): void
    {
        $result = PatientImport::parseImport('fullName,sex\nAlmaz,FEMALE', $this->ctx());

        $this->assertSame(['phone'], $result['missingColumns']);
        $this->assertCount(0, $result['rows']);
    }

    public function test_returns_no_rows_for_a_header_only_file(): void
    {
        $result = PatientImport::parseImport(self::HEADER, $this->ctx());

        $this->assertSame([], $result['missingColumns']);
        $this->assertCount(0, $result['rows']);
        $this->assertSame(0, $result['validCount']);
    }

    public function test_flags_files_above_the_row_cap(): void
    {
        $rows = [];
        for ($i = 0; $i <= PatientImport::MAX_IMPORT_ROWS; $i++) {
            $rows[] = 'Patient '.$i.',0911'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        }

        $result = PatientImport::parseImport($this->csv(self::HEADER, ...$rows), $this->ctx());

        $this->assertTrue($result['tooManyRows']);
        $this->assertCount(0, $result['rows']);
    }

    public function test_handles_bom_quoted_cells_and_crlf(): void
    {
        $text = "\u{FEFF}".self::HEADER."\r\n".
            "\"Almaz, Tesfaye\",0911223344,FEMALE,1985-03-12,\"glasses; follow-up\",en\r\n";

        $result = PatientImport::parseImport($text, $this->ctx());

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Almaz, Tesfaye', $result['rows'][0]['values']['fullName']);
        $this->assertSame(['glasses', 'follow-up'], $result['rows'][0]['values']['tags']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function phoneProvider(): iterable
    {
        yield 'national' => ['0911223344', '+251911223344'];
        yield 'short' => ['911223344', '+251911223344'];
        yield 'e164' => ['+251911223344', '+251911223344'];
        yield 'spaced' => ['09 11 22 33 44', '+251911223344'];
        yield 'dashed' => ['0911-223-344', '+251911223344'];
        yield 'zero-seven' => ['0711223344', '+251711223344'];
        yield 'zero-seven-e164' => ['+251711223344', '+251711223344'];
    }

    #[DataProvider('phoneProvider')]
    public function test_normalizes_phones(string $raw, string $expected): void
    {
        $result = PatientImport::parseImport($this->csv(self::HEADER, "Almaz,{$raw}"), $this->ctx());

        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertSame($expected, $result['rows'][0]['values']['phoneE164']);
    }

    public function test_rejects_invalid_phones_with_a_per_row_error(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,12345', 'Bekele,09112'),
            $this->ctx(),
        );

        $this->assertSame(0, $result['validCount']);
        $this->assertSame(2, $result['errorCount']);
        $this->assertContains(['field' => 'phone', 'key' => 'invalidPhone'], $result['rows'][0]['errors']);
        $this->assertContains(['field' => 'phone', 'key' => 'invalidPhone'], $result['rows'][1]['errors']);
    }

    public function test_marks_a_phone_that_already_exists_in_the_database(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,0911223344'),
            $this->ctx(['existingPhones' => ['+251911223344']]),
        );

        $this->assertContains(['field' => 'phone', 'key' => 'phoneTaken'], $result['rows'][0]['errors']);
        $this->assertNull($result['rows'][0]['values']);
    }

    public function test_marks_a_phone_repeated_inside_the_same_file(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,0911223344', 'Duplicate,911223344'),
            $this->ctx(),
        );

        $this->assertSame([], $result['rows'][0]['errors']);
        $this->assertContains(['field' => 'phone', 'key' => 'phoneTaken'], $result['rows'][1]['errors']);
        $this->assertSame(1, $result['validCount']);
        $this->assertSame(1, $result['errorCount']);
    }

    public function test_requires_a_name(): void
    {
        $result = PatientImport::parseImport($this->csv(self::HEADER, ',0911223344'), $this->ctx());

        $this->assertContains(['field' => 'fullName', 'key' => 'required'], $result['rows'][0]['errors']);
    }

    public function test_rejects_bad_sex_dates_and_language(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,0911223344,X,1985-03-12,,fr'),
            $this->ctx(),
        );

        $keys = $this->errorKeys($result['rows'][0]);
        $this->assertContains('invalidSex', $keys);
        $this->assertContains('invalidLanguage', $keys);
        $this->assertContains(['field' => 'preferredLanguage', 'key' => 'invalidLanguage'], $result['rows'][0]['errors']);
    }

    public function test_rejects_future_and_malformed_birth_dates(): void
    {
        $future = gmdate('Y-m-d', time() + 86400);

        $result = PatientImport::parseImport($this->csv(
            self::HEADER,
            'A,0911223341,,,,,',
            "B,0911223342,MALE,{$future}",
            'C,0911223343,MALE,12/31/1990',
        ), $this->ctx());

        $this->assertCount(0, $result['rows'][0]['errors']);
        $this->assertContains(['field' => 'birthDate', 'key' => 'invalidDate'], $result['rows'][1]['errors']);
        $this->assertContains(['field' => 'birthDate', 'key' => 'invalidDate'], $result['rows'][2]['errors']);
    }

    public function test_applies_the_default_language_when_the_column_is_empty(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,0911223344'),
            $this->ctx(['defaultLanguage' => 'am']),
        );

        $this->assertSame('am', $result['rows'][0]['values']['preferredLanguage']);
    }

    public function test_validates_only_failing_rows_and_keeps_the_good_ones(): void
    {
        $result = PatientImport::parseImport($this->csv(
            self::HEADER,
            'Almaz,0911223344,FEMALE,1985-03-12,glasses,en',
            ',0911223345',
            'Bekele,not-a-phone',
            'Tigist,0911223346,FEMALE,1992-07-25,,am',
        ), $this->ctx());

        $this->assertSame([2, 3, 4, 5], array_column($result['rows'], 'rowNumber'));
        $this->assertSame(2, $result['validCount']);
        $this->assertSame(2, $result['errorCount']);
        $this->assertNotNull($result['rows'][0]['values']);
        $this->assertNull($result['rows'][1]['values']);
        $this->assertNull($result['rows'][2]['values']);
        $this->assertNotNull($result['rows'][3]['values']);
    }

    public function test_accepts_sex_aliases_case_insensitively(): void
    {
        $result = PatientImport::parseImport($this->csv(
            self::HEADER,
            'A,0911223341,m',
            'B,0911223342,FEMALE',
            'C,0911223343,Other',
        ), $this->ctx());

        $this->assertSame('MALE', $result['rows'][0]['values']['sex']);
        $this->assertSame('FEMALE', $result['rows'][1]['values']['sex']);
        $this->assertSame('OTHER', $result['rows'][2]['values']['sex']);
    }

    public function test_lowercases_and_dedupes_tag_cells(): void
    {
        $result = PatientImport::parseImport(
            $this->csv(self::HEADER, 'Almaz,0911223344,MALE,1990-01-01,"Glasses; glasses, follow-up",en'),
            $this->ctx(),
        );

        $this->assertSame(['glasses', 'follow-up'], $result['rows'][0]['values']['tags']);
    }

    public function test_every_error_has_a_report_message(): void
    {
        // Every key the validator can emit must exist in the English report map.
        $keys = ['required', 'tooLong', 'invalid', 'invalidPhone', 'phoneTaken', 'invalidSex', 'invalidDate', 'invalidLanguage'];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, PatientImport::IMPORT_ERROR_MESSAGES);
        }
    }
}
