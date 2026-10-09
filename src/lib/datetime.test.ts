import { describe, expect, it } from 'vitest';
import {
  clinicDateKey,
  clinicTimeKey,
  combineDateAndTime,
  dateKeyAdd,
  endOfDay,
  formatInTz,
  startOfDay,
  tzOffsetMs,
} from './datetime';

const ADDIS = 'Africa/Addis_Ababa';

describe('datetime (Africa/Addis_Ababa, UTC+3)', () => {
  it('keeps a constant +3h offset (no DST)', () => {
    expect(tzOffsetMs(new Date('2026-01-15T12:00:00Z'), ADDIS)).toBe(3 * 3600 * 1000);
    expect(tzOffsetMs(new Date('2026-07-15T12:00:00Z'), ADDIS)).toBe(3 * 3600 * 1000);
  });

  it('derives the local date key from a UTC instant', () => {
    expect(clinicDateKey('2026-10-08T20:59:00Z', ADDIS)).toBe('2026-10-08'); // 23:59 local
    expect(clinicDateKey('2026-10-08T21:00:00Z', ADDIS)).toBe('2026-10-09'); // 00:00 local
    expect(clinicDateKey('2026-10-08T23:30:00Z', 'UTC')).toBe('2026-10-08');
  });

  it('derives the local time key', () => {
    expect(clinicTimeKey('2026-10-08T05:30:00Z', ADDIS)).toBe('08:30');
    expect(clinicTimeKey('2026-10-08T21:15:00Z', ADDIS)).toBe('00:15');
  });

  it('converts local wall time to the UTC instant', () => {
    expect(combineDateAndTime('2026-10-08', '08:30', ADDIS).toISOString()).toBe(
      '2026-10-08T05:30:00.000Z',
    );
    expect(combineDateAndTime('2026-10-08', '00:00', ADDIS).toISOString()).toBe(
      '2026-10-07T21:00:00.000Z',
    );
  });

  it('computes local start/end of day as UTC instants', () => {
    expect(startOfDay('2026-10-08T10:00:00Z', ADDIS).toISOString()).toBe(
      '2026-10-07T21:00:00.000Z',
    );
    expect(endOfDay('2026-10-08T10:00:00Z', ADDIS).toISOString()).toBe('2026-10-08T21:00:00.000Z');
  });

  it('does calendar-day arithmetic across month/year boundaries', () => {
    expect(dateKeyAdd('2026-12-31', 1)).toBe('2027-01-01');
    expect(dateKeyAdd('2026-03-01', -1)).toBe('2026-02-28');
    expect(dateKeyAdd('2024-02-28', 1)).toBe('2024-02-29');
  });

  it('formats in the target timezone', () => {
    expect(formatInTz('2026-10-08T05:30:00Z', ADDIS, { hour: '2-digit', minute: '2-digit' })).toBe(
      '08:30',
    );
    expect(formatInTz('2026-10-08T21:30:00Z', ADDIS, { hour: '2-digit', minute: '2-digit' })).toBe(
      '00:30',
    );
  });
});
