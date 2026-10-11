import { describe, expect, it } from 'vitest';
import {
  GROUP_BATCH_HARD_MAX,
  GROUP_BATCH_HARD_MIN,
  emptyPlan,
  planBatches,
  resolveGroupBatchSize,
} from './group-plan';

describe('planBatches', () => {
  it('splits 40 into [15, 15, 10] with size 15', () => {
    expect(planBatches(40, 15)).toEqual([15, 15, 10]);
  });

  it('returns a single batch when count fits', () => {
    expect(planBatches(7, 15)).toEqual([7]);
  });

  it('returns empty for zero or negative counts', () => {
    expect(planBatches(0, 15)).toEqual([]);
    expect(planBatches(-3, 15)).toEqual([]);
  });

  it('fills earlier batches fully', () => {
    expect(planBatches(38, 15)).toEqual([15, 15, 8]);
  });

  it('clamps pathological batch sizes', () => {
    expect(planBatches(10, 0)).toHaveLength(10); // min clamp → 1 per batch
    expect(planBatches(10, 1000)).toEqual([10]); // max clamp → one batch
    expect(planBatches(250, 100)).toHaveLength(3);
  });

  it('does not exceed the hard maximum per batch', () => {
    const batches = planBatches(501, 1000);
    expect(Math.max(...batches)).toBe(GROUP_BATCH_HARD_MAX);
  });
});

describe('resolveGroupBatchSize', () => {
  it('defaults to 15', () => {
    expect(resolveGroupBatchSize(undefined)).toBe(15);
    expect(resolveGroupBatchSize('oops')).toBe(15);
  });

  it('clamps to the hard bounds', () => {
    expect(resolveGroupBatchSize(0)).toBe(GROUP_BATCH_HARD_MIN);
    expect(resolveGroupBatchSize(5000)).toBe(GROUP_BATCH_HARD_MAX);
  });

  it('floors decimals', () => {
    expect(resolveGroupBatchSize(15.9)).toBe(15);
  });
});

describe('emptyPlan', () => {
  it('returns an empty batch list', () => {
    expect(emptyPlan()).toEqual([]);
  });
});