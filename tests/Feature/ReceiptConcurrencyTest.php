<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sale;
use App\Support\ClinicTime;
use App\Support\Roles;
use App\Support\Sales\Receipt;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * MySQL-only behaviour: the receipt-number claim under real concurrency.
 *
 * SQLite serialises writes inside one process, so the claim can never race
 * there. These tests only run on MySQL (they are skipped on SQLite) and they
 * drive **separate PHP processes** against the same database, so the claim is
 * exercised the way production does it.
 */
class ReceiptConcurrencyTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;

    // The workers commit on their own connections, so the schema must be
    // rebuilt per test (a rolled-back transaction would not clean them up).
    use DatabaseMigrations;

    private const TZ = 'Africa/Addis_Ababa';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The concurrency claim needs a real MySQL server (SQLite serialises writes in-process).');
        }
    }

    /**
     * Runs N separate PHP processes that each confirm one sale against the
     * same database, then asserts the numbers are unique and gap-free.
     */
    public function test_fifty_parallel_sales_claim_unique_gap_free_receipt_numbers(): void
    {
        $service = $this->createService(['current_price' => '100.00']);
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $workers = 50;
        $year = (int) substr(ClinicTime::clinicDateKey(now(), self::TZ), 0, 4);

        // Each worker signs in and confirms one sale, exactly like the UI does.
        $script = <<<'PHP'
<?php
$root = $argv[1];
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ownerId = $argv[2];
$serviceId = $argv[3];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$receipt = App\Support\Sales\Receipt::nextReceiptNumber($now, 'Africa/Addis_Ababa');

$total = App\Support\Money::multiplyMoney('100.00', 1);
$nowIso = $now->format('Y-m-d H:i:s');

Illuminate\Support\Facades\DB::connection()->transaction(function () use ($ownerId, $serviceId, $receipt, $total, $nowIso) {
    $sale = App\Models\Sale::create([
        'receipt_number' => $receipt,
        'sale_date' => $nowIso,
        'status' => 'CONFIRMED',
        'payment_method' => 'CASH',
        'total' => $total,
        'created_by' => $ownerId,
        'confirmed_at' => $nowIso,
    ]);

    App\Models\SaleLine::create([
        'sale_id' => $sale->id,
        'service_id' => $serviceId,
        'service_name_snapshot' => 'Concurrency Service',
        'qty' => 1,
        'unit_price' => '100.00',
        'line_total' => $total,
    ]);
});

echo $receipt;
PHP;

        $scriptPath = base_path('storage/app/receipt-worker.php');
        file_put_contents($scriptPath, $script);

        $root = base_path();
        $commands = [];
        for ($i = 0; $i < $workers; $i++) {
            $commands[] = sprintf(
                'php %s %s %s %s >%s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($root),
                escapeshellarg($owner->id),
                escapeshellarg($service->id),
                escapeshellarg(storage_path('logs/receipt-'.$i.'.out'))
            );
        }

        // Fire every worker at once.
        $processes = [];
        $pipes = [];
        foreach ($commands as $index => $command) {
            $processes[$index] = proc_open(
                'cd '.escapeshellarg($root).' && '.$command,
                [1 => ['file', storage_path('logs/receipt-'.$index.'.out'), 'w'], 2 => ['file', storage_path('logs/receipt-'.$index.'.err'), 'w']],
                $pipes[$index]
            );
        }

        foreach ($processes as $index => $process) {
            proc_close($process);
        }

        @unlink($scriptPath);

        // Every worker must have claimed a number.
        $claimed = [];
        for ($i = 0; $i < $workers; $i++) {
            $out = trim((string) @file_get_contents(storage_path('logs/receipt-'.$i.'.out')));
            $err = trim((string) @file_get_contents(storage_path('logs/receipt-'.$i.'.err')));

            $this->assertNotSame('', $out, "Worker {$i} claimed no receipt number. {$err}");
            $claimed[] = $out;
        }

        // 1. No duplicates.
        $this->assertCount($workers, array_unique($claimed), 'Two workers claimed the same receipt number.');

        // 2. No gaps: the numbers are 1..N with no hole.
        $numbers = array_map(static fn (string $receipt): int => (int) substr($receipt, 5), $claimed);
        sort($numbers);

        $this->assertSame(range(1, $workers), $numbers, 'The claimed receipt numbers are not a gap-free 1..N sequence.');

        // 3. The database agrees with the counters.
        $this->assertSame($workers, Sale::query()->count());
        $this->assertSame($workers, DB::table('receipt_counters')->where('year', $year)->value('last_number'));

        $stored = Sale::query()->pluck('receipt_number')->sort()->values()->all();
        $expected = array_map(static fn (int $n): string => sprintf('%d-%06d', $year, $n), range(1, $workers));

        $this->assertSame($expected, $stored);
    }

    public function test_a_failed_sale_does_not_burn_a_receipt_number(): void
    {
        $service = $this->createService(['current_price' => '100.00']);
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $before = (int) (DB::table('receipt_counters')->where('year', 2026)->value('last_number') ?? 0);

        // A sale whose line references a missing service fails AFTER the
        // counter was claimed — the transaction rolls back, so the number is
        // released.
        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => 'does-not-exist', 'qty' => 1]]),
        ])->assertSessionHasErrors('lines');

        $after = (int) (DB::table('receipt_counters')->where('year', 2026)->value('last_number') ?? 0);

        $this->assertSame($before, $after, 'A failed sale burned a receipt number.');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_counter_rolls_over_to_a_new_year(): void
    {
        $service = $this->createService(['current_price' => '100.00']);
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // Claim in 2026, then in 2027 — each year has its own counter.
        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $this->assertSame(1, (int) DB::table('receipt_counters')->where('year', 2026)->value('last_number'));

        // A December 31 sale belongs to the next clinic year when it is after
        // the local midnight.
        $december = new \DateTimeImmutable('2026-12-31T22:00:00Z'); // 01:00 local on Jan 1
        $receipt = Receipt::nextReceiptNumber($december, self::TZ);

        $this->assertStringStartsWith('2027-', $receipt);
        $this->assertSame(1, (int) DB::table('receipt_counters')->where('year', 2027)->value('last_number'));
        $this->assertSame(1, (int) DB::table('receipt_counters')->where('year', 2026)->value('last_number'));
    }

    public function test_the_receipt_year_follows_the_clinic_timezone_not_utc(): void
    {
        // 21:00Z is 00:00 the next day in Addis Ababa (UTC+3).
        $instant = new \DateTimeImmutable('2026-12-31T21:00:00Z');

        $this->assertSame(2027, Receipt::receiptYear($instant, self::TZ));
        $this->assertSame(2026, Receipt::receiptYear($instant, 'UTC'));
    }
}
