<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Service;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Patients\PatientQueries;
use App\Support\Sales\Revenue;
use App\Support\Sales\SaleQueries;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * MySQL-only behaviour: DECIMAL arithmetic, day boundaries in the clinic
 * timezone, uploads, Amharic collation and index key lengths.
 *
 * These assertions are only meaningful on MySQL — SQLite has no DECIMAL
 * type, no utf8mb4_0900_ai_ci collation and a different index limit — so
 * they are skipped elsewhere.
 */
class MySqlSemanticsTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;

    // `migrate:fresh` per test: this class inspects the real schema.
    use DatabaseMigrations;

    private const TZ = 'Africa/Addis_Ababa';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('These assertions are specific to MySQL 8.0.');
        }
    }

    /** A doctor row (the helper lives in the appointments concern). */
    private function doctor(array $overrides = []): Staff
    {
        return Staff::factory()->create(array_merge([
            'is_doctor' => true,
            'active' => true,
        ], $overrides));
    }

    /** A patient with an explicit owner (the patient factory needs one). */
    private function patient(array $overrides = []): Patient
    {
        $owner = $this->createUser(['role' => 'OWNER']);

        return Patient::factory()->create(array_merge([
            'created_by' => $owner->id,
        ], $overrides));
    }

    // ---------------------------------------------------------------------
    // (b) DECIMAL(12,2) rounding and revenue totals
    // ---------------------------------------------------------------------

    public function test_decimal_columns_round_half_up_like_the_money_helper(): void
    {
        $service = Service::factory()->create(['current_price' => '0.005']);

        // DECIMAL(12,2) rounds half away from zero on write.
        $service->refresh();

        $this->assertSame('0.01', Money::toDecimalString($service->current_price));
        $this->assertSame('0.01', Money::money('0.005'));
        $this->assertSame('2.68', Money::money('2.675'));
        $this->assertSame('-2.68', Money::money('-2.675'));
    }

    public function test_the_stored_total_is_the_exact_decimal_sum(): void
    {
        $service = $this->createService(['current_price' => '0.01']);

        // 3 × 0.01 — a float would drift, DECIMAL must not.
        $sale = $this->createSale(['status' => 'CONFIRMED'], [
            ['service' => $service, 'qty' => 3, 'unitPrice' => '0.01'],
        ]);
        $sale->forceFill(['total' => '0.03'])->save();

        $stored = DB::table('sales')->where('id', $sale->id)->value('total');

        $this->assertSame('0.03', (string) $stored);
    }

    public function test_the_revenue_total_matches_an_independent_sql_calculation(): void
    {
        $owner = $this->createUser(['role' => 'OWNER']);
        $service = $this->createService(['current_price' => '19.99']);
        $doctor = $this->doctor();

        $today = ClinicTime::clinicDateKey(now(), self::TZ);
        $from = ClinicTime::combineDateAndTime($today, '00:00', self::TZ);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($today, 1), '00:00', self::TZ);

        for ($i = 0; $i < 7; $i++) {
            $this->createSale([
                'created_by' => $owner->id,
                'doctor_id' => $doctor->id,
                'status' => 'CONFIRMED',
                'sale_date' => $from->modify("+{$i} hours")->format('Y-m-d H:i:s'),
            ], [
                ['service' => $service, 'qty' => 3, 'unitPrice' => '19.99'],
            ]);
        }

        // The independent calculation: plain SQL over the same rows.
        $sqlTotal = DB::table('sales')
            ->where('status', 'CONFIRMED')
            ->where('sale_date', '>=', $from->format('Y-m-d H:i:s'))
            ->where('sale_date', '<', $to->format('Y-m-d H:i:s'))
            ->sum('total');

        $report = Revenue::buildRevenueReport(
            SaleQueries::listRevenueSales($from, $to),
            self::TZ,
        );

        // 7 × (3 × 19.99) = 419.79 exactly.
        $this->assertSame('419.79', (string) $sqlTotal);
        $this->assertSame('419.79', $report['totalRevenue']);
        $this->assertSame(7, $report['salesCount']);
        $this->assertSame('59.97', $report['averageSale']);
    }

    public function test_the_sum_of_a_decimal_column_is_exact_not_a_float(): void
    {
        $service = $this->createService(['current_price' => '0.10']);
        $owner = $this->createUser(['role' => 'OWNER']);

        for ($i = 0; $i < 10; $i++) {
            $this->createSale(['created_by' => $owner->id, 'status' => 'CONFIRMED'], [
                ['service' => $service, 'qty' => 1, 'unitPrice' => '0.10'],
            ]);
        }

        $sum = DB::table('sales')->where('status', 'CONFIRMED')->sum('total');

        // MySQL returns a DECIMAL sum as a STRING — 1.00 exactly.
        $this->assertSame('1.00', (string) $sum);
        $this->assertSame('1.00', Money::toDecimalString($sum));
    }

    // ---------------------------------------------------------------------
    // (c) Day-boundary queries in Africa/Addis_Ababa (UTC+3, no DST)
    // ---------------------------------------------------------------------

    public function test_a_day_bucket_uses_the_clinic_timezone_boundaries(): void
    {
        $owner = $this->createUser(['role' => 'OWNER']);
        $service = $this->createService(['current_price' => '10.00']);

        $dayKey = '2026-10-08';

        // 21:30Z on Oct 7 is 00:30 on Oct 8 in Addis → INSIDE the day.
        $inside = $this->createSale([
            'created_by' => $owner->id,
            'status' => 'CONFIRMED',
            'sale_date' => '2026-10-07 21:30:00',
        ], [['service' => $service, 'qty' => 1]]);

        // 20:59Z on Oct 7 is 23:59 on Oct 7 in Addis → OUTSIDE (the day before).
        $before = $this->createSale([
            'created_by' => $owner->id,
            'status' => 'CONFIRMED',
            'sale_date' => '2026-10-07 20:59:00',
        ], [['service' => $service, 'qty' => 1]]);

        // 21:00Z on Oct 8 is 00:00 on Oct 9 → OUTSIDE (the next day).
        $after = $this->createSale([
            'created_by' => $owner->id,
            'status' => 'CONFIRMED',
            'sale_date' => '2026-10-08 21:00:00',
        ], [['service' => $service, 'qty' => 1]]);

        // The rows really are where we think they are.
        $this->assertSame('2026-10-08', ClinicTime::clinicDateKey($inside->sale_date, self::TZ));
        $this->assertSame('2026-10-07', ClinicTime::clinicDateKey($before->sale_date, self::TZ));
        $this->assertSame('2026-10-09', ClinicTime::clinicDateKey($after->sale_date, self::TZ));

        // `listRevenueSales` takes INSTANTS; `listSales` takes date keys.
        $rows = SaleQueries::listRevenueSales(
            ClinicTime::combineDateAndTime($dayKey, '00:00', self::TZ),
            ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dayKey, 1), '00:00', self::TZ),
        );

        $ids = array_column($rows, 'id');

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_the_end_of_day_is_the_exclusive_next_midnight(): void
    {
        $dayKey = '2026-10-08';

        $from = ClinicTime::combineDateAndTime($dayKey, '00:00', self::TZ);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dayKey, 1), '00:00', self::TZ);

        // 2026-10-07 21:00:00Z = 2026-10-08 00:00 local.
        $this->assertSame('2026-10-07 21:00:00', $from->format('Y-m-d H:i:s'));
        // 2026-10-08 21:00:00Z = 2026-10-09 00:00 local.
        $this->assertSame('2026-10-08 21:00:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_a_midnight_appointment_lands_in_the_right_clinic_day(): void
    {
        // 2026-10-08 18:00Z = 2026-10-08 21:00 local → the same day.
        $instant = new \DateTimeImmutable('2026-10-08 18:00:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-10-08', ClinicTime::clinicDateKey($instant, self::TZ));

        // 2026-10-08 21:30Z = 2026-10-09 00:30 local → the next day.
        $instant = new \DateTimeImmutable('2026-10-08 21:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-10-09', ClinicTime::clinicDateKey($instant, self::TZ));
    }

    // ---------------------------------------------------------------------
    // (e) Amharic case-insensitive search, uniqueness and sorting
    // ---------------------------------------------------------------------

    public function test_amharic_search_is_case_insensitive(): void
    {
        $this->patient(['full_name' => 'ሙሉ ዓይን ክሊኒክ']);
        $this->patient(['full_name' => 'ሌላ አይን ክሊኒክ']);

        $found = PatientQueries::list(['q' => 'ሙሉ'])['rows'];

        $this->assertCount(1, $found);
        $this->assertSame('ሙሉ ዓይን ክሊኒክ', $found[0]->full_name);
    }

    public function test_amharic_collation_ignores_case_and_accent_variants(): void
    {
        // utf8mb4_0900_ai_ci is accent- and case-insensitive.
        $this->patient(['full_name' => 'ሀበበ']);
        $this->patient(['full_name' => 'ሀበበ']);

        $found = DB::table('patients')
            ->where('full_name', 'like', '%ሀበበ%')
            ->count();

        $this->assertSame(2, $found, 'The utf8mb4_0900_ai_ci collation did not match the two spellings.');
    }

    public function test_amharic_sorting_is_unicode_aware(): void
    {
        $names = ['ሀ', 'ሁ', 'ሂ', 'ሃ'];
        foreach ($names as $index => $name) {
            $this->patient(['full_name' => $name.' ታስቲ', 'phone_e164' => '+25191100000'.$index]);
        }

        $sorted = PatientQueries::list(['q' => 'ታስቲ'])['rows'];
        $sortedNames = $sorted->pluck('full_name')->all();

        $this->assertCount(4, $sortedNames);
        $this->assertSame($names, array_map(
            static fn (string $full): string => mb_substr($full, 0, 1),
            $sortedNames,
        ));
    }

    public function test_the_phone_unique_index_holds_under_utf8mb4(): void
    {
        $patient = $this->patient(['phone_e164' => '+251911223344']);

        $this->expectException(UniqueConstraintViolationException::class);

        Patient::create([
            'full_name' => 'Duplicate Phone',
            'phone_e164' => '+251911223344',
            'sex' => 'MALE',
            'birth_date' => '1990-01-01',
            'preferred_language' => 'en',
            'active' => true,
            'created_by' => $patient->created_by,
        ]);
    }

    // ---------------------------------------------------------------------
    // (f) Index key lengths
    // ---------------------------------------------------------------------

    public function test_every_index_key_fits_the_mysql_limit(): void
    {
        $rowFormat = (string) DB::selectOne('SELECT @@innodb_default_row_format AS format')->format;
        $maxKeyLength = $rowFormat === 'COMPACT' || $rowFormat === 'REDUNDANT' ? 767 : 3072;

        $indexes = DB::select(
            'SELECT table_name, index_name, seq_in_index, column_name, sub_part
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
              ORDER BY table_name, index_name, seq_in_index'
        );

        $this->assertNotEmpty($indexes, 'No indexes were found.');

        // Group the rows per index and add up the key bytes.
        $byIndex = [];
        foreach ($indexes as $row) {
            $byIndex[$row->TABLE_NAME.'/'.$row->INDEX_NAME][] = $row;
        }

        foreach ($byIndex as $key => $rows) {
            $bytes = 0;

            foreach ($rows as $row) {
                $column = DB::selectOne(
                    'SELECT character_maximum_length AS len, data_type AS type
                       FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name = ? AND column_name = ?',
                    [$row->TABLE_NAME, $row->COLUMN_NAME]
                );

                $length = $row->SUB_PART !== null
                    ? (int) $row->SUB_PART
                    : (int) ($column->len ?? 8);

                // utf8mb4 = 4 bytes per character.
                $bytes += $length * 4;
            }

            $this->assertLessThanOrEqual(
                $maxKeyLength,
                $bytes,
                "The index {$key} needs {$bytes} bytes of key length; MySQL allows {$maxKeyLength}.",
            );
        }
    }

    public function test_the_widest_indexed_column_still_fits_in_one_key(): void
    {
        $rows = DB::select(
            'SELECT s.table_name, s.index_name, s.column_name, s.sub_part, c.character_maximum_length AS len
               FROM information_schema.statistics s
               JOIN information_schema.columns c
                 ON c.table_schema = DATABASE()
                AND c.table_name = s.table_name
                AND c.column_name = s.column_name
              WHERE s.table_schema = DATABASE()
                AND c.character_maximum_length IS NOT NULL
              ORDER BY c.character_maximum_length DESC
              LIMIT 10'
        );

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $length = $row->SUB_PART !== null ? (int) $row->SUB_PART : (int) $row->len;

            $this->assertLessThanOrEqual(
                3072,
                $length * 4,
                'The indexed column '.$row->TABLE_NAME.'.'.$row->COLUMN_NAME
                    .' needs '.($length * 4).' bytes of key length.',
            );
        }
    }
}
