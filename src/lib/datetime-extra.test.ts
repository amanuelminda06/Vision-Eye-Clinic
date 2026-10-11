import { describe, it, expect } from 'vitest';
import { combineDateAndTime, clinicTimeKey, tzOffsetMs } from './datetime';

describe('combineDateAndTime edge cases', () => {
  it('rejects malformed date/time keys', () => {
    expect(() => combineDateAndTime('not-a-date', '12:00')).toThrow();
    expect(() => combineDateAndTime('2026-10-12', 'oops')).toThrow();
    expect(() => combineDateAndTime('', '')).toThrow();
  });

  it('handles a DST transition by refining the offset (spring forward)', () => {
    const ny = 'America/New_York';
    const dt = combineDateAndTime('2026-03-08', '02:30', ny); // 02:30 does not exist on this date
    expect(dt).toBeInstanceOf(Date);
    const local = clinicTimeKey(dt, ny);
    expect(['01:30', '03:30']).toContain(local);
  });
});

describe('tzOffsetMs', () => {
  it('returns correct offset for a half-hour timezone', () => {
    // Kathmandu is UTC+5:45 -> offsets differ from whole hours
    const off = tzOffsetMs(new Date('2026-06-01T00:00:00.000Z'), 'Asia/Kathmandu');
    expect(off).toBe(5 * 3_600_000 + 45 * 60_000);
  });
});