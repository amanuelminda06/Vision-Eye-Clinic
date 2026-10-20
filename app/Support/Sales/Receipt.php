<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Support\ClinicTime;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-year receipt numbers (`2026-000123`), ported from
 * src/modules/sales/receipt.ts.
 *
 * The counter is claimed with ONE atomic statement, executed inside the sale
 * transaction. On MySQL the row is locked by the upsert so concurrent claims
 * serialize and never share a number; because the claim runs in the same
 * transaction as the sale, a failed sale rolls the counter back too → no gaps.
 * SQLite (tests) serializes writes, so the same code is safe there.
 */
final class Receipt
{
    public const RECEIPT_PAD = 6;

    /**
     * `formatReceiptNumber(2026, 123)` → `2026-000123`.
     *
     * @throws \InvalidArgumentException on non-integer year/value or value < 1
     */
    public static function formatReceiptNumber(int $year, int $value): string
    {
        if ($value < 1) {
            throw new \InvalidArgumentException("Invalid receipt number: {$year}-{$value}");
        }

        return $year.'-'.str_pad((string) $value, self::RECEIPT_PAD, '0', STR_PAD_LEFT);
    }

    /** Calendar year of a sale instant in the clinic timezone. */
    public static function receiptYear(\DateTimeInterface $date, string $timeZone): int
    {
        return (int) substr(ClinicTime::clinicDateKey($date, $timeZone), 0, 4);
    }

    /**
     * Claims the next integer for `year` (atomic — safe under concurrency).
     *
     * MySQL: `INSERT … ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)`.
     * `LAST_INSERT_ID(expr)` both sets the value and makes it readable via
     * `LAST_INSERT_ID()` on the same connection, so we claim and read in one
     * round trip inside the transaction.
     */
    public static function claimReceiptNumber(int $year): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite has no upsert-with-returning; tests serialize anyway.
            DB::statement(
                'INSERT INTO receipt_counters (year, last_number) VALUES (?, 1)
                 ON CONFLICT (year) DO UPDATE SET last_number = last_number + 1',
                [$year],
            );

            return (int) DB::table('receipt_counters')->where('year', $year)->value('last_number');
        }

        DB::statement(
            'INSERT INTO receipt_counters (year, last_number) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$year],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS value')->value;
    }

    /**
     * Claims + formats in one call (inside the sale transaction).
     */
    public static function nextReceiptNumber(\DateTimeInterface $date, string $timeZone): string
    {
        $year = self::receiptYear($date, $timeZone);

        return self::formatReceiptNumber($year, self::claimReceiptNumber($year));
    }
}
