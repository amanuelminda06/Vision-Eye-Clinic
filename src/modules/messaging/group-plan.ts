/**
 * Pure grouping rules for "One group chat" delivery. The app never sends
 * automatically: it splits recipients into batches and the operator opens the
 * phone's SMS app once per batch. No DB / no React — unit-tested directly.
 */

export const GROUP_BATCH_HARD_MIN = 1;
export const GROUP_BATCH_HARD_MAX = 100;

export function emptyPlan(): number[] {
  return [];
}

/**
 * Splits `count` recipients into batches of at most `batchSize`, filling each
 * batch fully before starting the next (keeps the number of phone-app trips
 * minimal). Returns the size of every batch, e.g. planBatches(40, 15) →
 * [15, 15, 10].
 */
export function planBatches(count: number, batchSize: number): number[] {
  const total = Math.max(0, Math.floor(count));
  const size = Math.min(
    GROUP_BATCH_HARD_MAX,
    Math.max(GROUP_BATCH_HARD_MIN, Math.floor(batchSize) || GROUP_BATCH_HARD_MIN),
  );
  const batches: number[] = [];
  let remaining = total;
  while (remaining > 0) {
    const next = Math.min(size, remaining);
    batches.push(next);
    remaining -= next;
  }
  return batches;
}

/** Resolves the configured group batch size into the allowed bounds. */
export function resolveGroupBatchSize(value: unknown): number {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) return 15;
  return Math.min(GROUP_BATCH_HARD_MAX, Math.max(GROUP_BATCH_HARD_MIN, Math.floor(parsed)));
}
