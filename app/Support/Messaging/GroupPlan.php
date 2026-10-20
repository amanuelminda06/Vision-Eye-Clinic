<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * Pure grouping rules for "One group chat" delivery, ported from
 * src/modules/messaging/group-plan.ts.
 *
 * The app never sends automatically: it splits recipients into batches and the
 * operator opens the phone's SMS app once per batch.
 */
final class GroupPlan
{
    public const GROUP_BATCH_HARD_MIN = 1;

    public const GROUP_BATCH_HARD_MAX = 100;

    /** @return list<int> */
    public static function emptyPlan(): array
    {
        return [];
    }

    /**
     * Splits `count` recipients into batches of at most `batchSize`, filling
     * each batch fully before starting the next.
     *
     * @return list<int>
     */
    public static function planBatches(int|float $count, int|float $batchSize): array
    {
        $total = max(0, (int) floor($count));
        $size = min(
            self::GROUP_BATCH_HARD_MAX,
            max(self::GROUP_BATCH_HARD_MIN, (int) floor($batchSize) ?: self::GROUP_BATCH_HARD_MIN),
        );

        $batches = [];
        $remaining = $total;

        while ($remaining > 0) {
            $next = min($size, $remaining);
            $batches[] = $next;
            $remaining -= $next;
        }

        return $batches;
    }

    /**
     * Resolves the configured group batch size into the allowed bounds.
     *
     * Bad input defaults to 15 (unlike planBatches, which clamps to 1).
     */
    public static function resolveGroupBatchSize(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            $parsed = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $parsed = (float) trim($value);
        } else {
            return 15;
        }

        if (! is_finite($parsed)) {
            return 15;
        }

        return min(self::GROUP_BATCH_HARD_MAX, max(self::GROUP_BATCH_HARD_MIN, (int) floor($parsed)));
    }
}
