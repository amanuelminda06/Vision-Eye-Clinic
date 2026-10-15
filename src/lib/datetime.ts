/**
 * Date/time helpers (rule 7): everything is stored in UTC and computed in the
 * clinic timezone (default Africa/Addis_Ababa, from settings). Never use the
 * server's local timezone.
 */

export const DEFAULT_TIMEZONE = 'Africa/Addis_Ababa';

export type DateInput = Date | string;

function toDate(input: DateInput): Date {
  return input instanceof Date ? input : new Date(input);
}

type TzParts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
};

function tzParts(date: Date, timeZone: string): TzParts {
  const dtf = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  });
  const parts = dtf.formatToParts(date);
  const get = (type: Intl.DateTimeFormatPartTypes): number =>
    Number(parts.find((p) => p.type === type)?.value ?? '0');
  return {
    year: get('year'),
    month: get('month'),
    day: get('day'),
    hour: get('hour'),
    minute: get('minute'),
    second: get('second'),
  };
}

/** Offset (ms) of `timeZone` at the given instant: local-as-UTC minus true UTC. */
export function tzOffsetMs(date: Date, timeZone: string): number {
  const p = tzParts(date, timeZone);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  const base = Math.floor(date.getTime() / 1000) * 1000;
  return asUtc - base;
}

/** 'YYYY-MM-DD' of the instant in the given timezone. */
export function clinicDateKey(input: DateInput, timeZone: string = DEFAULT_TIMEZONE): string {
  const p = tzParts(toDate(input), timeZone);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${p.year}-${pad(p.month)}-${pad(p.day)}`;
}

/** 'HH:mm' of the instant in the given timezone. */
export function clinicTimeKey(input: DateInput, timeZone: string = DEFAULT_TIMEZONE): string {
  const p = tzParts(toDate(input), timeZone);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(p.hour)}:${pad(p.minute)}`;
}

/** Wall-clock date + time in a timezone → the UTC instant. */
export function combineDateAndTime(
  dateKey: string,
  timeKey: string,
  timeZone: string = DEFAULT_TIMEZONE,
): Date {
  const [y, m, d] = dateKey.split('-').map(Number);
  const [hh, mm] = timeKey.split(':').map(Number);
  if (![y, m, d, hh, mm].every((n) => Number.isInteger(n))) {
    throw new Error(`Invalid date/time: ${dateKey} ${timeKey}`);
  }
  const wallUtc = Date.UTC(y, (m || 1) - 1, d || 1, hh || 0, mm || 0);
  let offset = tzOffsetMs(new Date(wallUtc), timeZone);
  let utc = wallUtc - offset;
  const offset2 = tzOffsetMs(new Date(utc), timeZone); // refine across DST edges
  if (offset2 !== offset) {
    offset = offset2;
    utc = wallUtc - offset;
  }
  return new Date(utc);
}

/** UTC instant of local midnight for the day containing `input`. */
export function startOfDay(input: DateInput, timeZone: string = DEFAULT_TIMEZONE): Date {
  return combineDateAndTime(clinicDateKey(input, timeZone), '00:00', timeZone);
}

/** Exclusive end of the local day containing `input` (local midnight next day). */
export function endOfDay(input: DateInput, timeZone: string = DEFAULT_TIMEZONE): Date {
  return combineDateAndTime(dateKeyAdd(clinicDateKey(input, timeZone), 1), '00:00', timeZone);
}

/** Pure calendar-day arithmetic on a 'YYYY-MM-DD' key. */
export function dateKeyAdd(dateKey: string, days: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days));
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${dt.getUTCFullYear()}-${pad(dt.getUTCMonth() + 1)}-${pad(dt.getUTCDate())}`;
}

/** Format an instant in the clinic timezone for display. */
export function formatInTz(
  input: DateInput,
  timeZone: string = DEFAULT_TIMEZONE,
  options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' },
  locale = 'en-GB',
): string {
  return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(toDate(input));
}

/** Format a date range (both @db.Date) in the clinic timezone. */
export function formatDateRange(
  startDate: Date | string,
  endDate: Date | string,
  timeZone: string = DEFAULT_TIMEZONE,
  locale = 'en-GB',
): string {
  const start = new Date(startDate);
  const end = new Date(endDate);
  const sameDay = start.getTime() === end.getTime();
  const sameDate =
    start.getUTCFullYear() === end.getUTCFullYear() &&
    start.getUTCMonth() === end.getUTCMonth() &&
    start.getUTCDate() === end.getUTCDate();
  const same = sameDay || sameDate;
  const fmt = (d: Date) =>
    new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone }).format(d);
  return same ? fmt(start) : `${fmt(start)} → ${fmt(end)}`;
}
