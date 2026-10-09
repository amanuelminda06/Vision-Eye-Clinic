import { clinicDateKey, combineDateAndTime, dateKeyAdd, DEFAULT_TIMEZONE } from '@/lib/datetime';

/**
 * Shift geometry (pure — unit tested). Shifts are UTC instants and may cross
 * midnight; overlap and generation must therefore work on instants, never on
 * wall-clock strings alone.
 */

export type ShiftLike = {
  id: string;
  staffId: string;
  startsAt: Date;
  endsAt: Date;
};

/** True when two half-open intervals [aStart, aEnd) and [bStart, bEnd) overlap. */
export function shiftsOverlap(aStart: Date, aEnd: Date, bStart: Date, bEnd: Date): boolean {
  return aStart.getTime() < bEnd.getTime() && bStart.getTime() < aEnd.getTime();
}

export function isValidShift(start: Date, end: Date): boolean {
  return start.getTime() < end.getTime();
}

/** First existing shift that overlaps the candidate (same staff member). */
export function findOverlappingShift<T extends ShiftLike>(
  existing: T[],
  candidate: { staffId: string; startsAt: Date; endsAt: Date; ignoreId?: string },
): T | undefined {
  return existing.find(
    (shift) =>
      shift.id !== candidate.ignoreId &&
      shift.staffId === candidate.staffId &&
      shiftsOverlap(shift.startsAt, shift.endsAt, candidate.startsAt, candidate.endsAt),
  );
}

/** True when the shift is currently running (`at` in [startsAt, endsAt)). */
export function isShiftActiveAt(shift: ShiftLike, at: Date): boolean {
  return at.getTime() >= shift.startsAt.getTime() && at.getTime() < shift.endsAt.getTime();
}

/** Earliest shift starting at/after `at` (null when none). */
export function nextShiftAfter<T extends ShiftLike>(shifts: T[], at: Date): T | null {
  let next: T | null = null;
  for (const shift of shifts) {
    if (shift.startsAt.getTime() >= at.getTime()) {
      if (!next || shift.startsAt < next.startsAt) next = shift;
    }
  }
  return next;
}

/** Weekday (0 = Sunday) of a `YYYY-MM-DD` key — timezone-independent. */
export function weekdayOf(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}

/** Normalises a `@db.Date` column value (UTC midnight) to a `YYYY-MM-DD` key. */
export function dateColumnToKey(value: Date | string): string {
  if (typeof value === 'string') return value.slice(0, 10);
  return value.toISOString().slice(0, 10);
}

export type TemplateInput = {
  id: string;
  staffId: string;
  weekday: number;
  /** "HH:mm" clinic-local. */
  startTime: string;
  /** "HH:mm" clinic-local; <= startTime means it crosses midnight. */
  endTime: string;
  validFrom: Date | string;
  validTo: Date | string | null;
};

export type GeneratedShift = {
  templateId: string;
  staffId: string;
  dateKey: string;
  startsAt: Date;
  endsAt: Date;
};

export function isOvernight(startTime: string, endTime: string): boolean {
  return endTime <= startTime;
}

/** Concrete instants for a template on one calendar day (clinic local). */
export function shiftInstantsForDay(
  template: Pick<TemplateInput, 'startTime' | 'endTime'>,
  dateKey: string,
  timeZone: string = DEFAULT_TIMEZONE,
): { startsAt: Date; endsAt: Date } {
  const startsAt = combineDateAndTime(dateKey, template.startTime, timeZone);
  const endKey = isOvernight(template.startTime, template.endTime)
    ? dateKeyAdd(dateKey, 1)
    : dateKey;
  const endsAt = combineDateAndTime(endKey, template.endTime, timeZone);
  return { startsAt, endsAt };
}

function templateAppliesOn(template: TemplateInput, dateKey: string): boolean {
  if (weekdayOf(dateKey) !== template.weekday) return false;
  const from = dateColumnToKey(template.validFrom);
  const to = template.validTo ? dateColumnToKey(template.validTo) : null;
  return dateKey >= from && (!to || dateKey <= to);
}

/** Inclusive list of date keys, capped at `maxDays` to bound work. */
export function dateKeysBetween(fromKey: string, toKey: string, maxDays = 400): string[] {
  const keys: string[] = [];
  let cursor = fromKey;
  for (let i = 0; i <= maxDays; i++) {
    keys.push(cursor);
    if (cursor === toKey) break;
    cursor = dateKeyAdd(cursor, 1);
  }
  return keys;
}

/**
 * Expands weekly templates into concrete shifts across `[fromKey, toKey]`
 * (clinic-local calendar days). Overnight templates end on the next day.
 * Pure: no DB, no clock — the caller supplies the date range.
 */
export function generateTemplateShifts(
  templates: TemplateInput[],
  fromKey: string,
  toKey: string,
  timeZone: string = DEFAULT_TIMEZONE,
  maxDays = 400,
): GeneratedShift[] {
  const generated: GeneratedShift[] = [];
  for (const dateKey of dateKeysBetween(fromKey, toKey, maxDays)) {
    for (const template of templates) {
      if (!templateAppliesOn(template, dateKey)) continue;
      const { startsAt, endsAt } = shiftInstantsForDay(template, dateKey, timeZone);
      generated.push({
        templateId: template.id,
        staffId: template.staffId,
        dateKey,
        startsAt,
        endsAt,
      });
    }
  }
  return generated.sort((a, b) => a.startsAt.getTime() - b.startsAt.getTime());
}

/** Monday-based start of the week containing `dateKey`. */
export function weekStartKey(dateKey: string): string {
  const sinceMonday = (weekdayOf(dateKey) + 6) % 7;
  return dateKeyAdd(dateKey, -sinceMonday);
}

/** Builds a 7-day key list starting at `startKey`. */
export function weekDays(startKey: string): string[] {
  return Array.from({ length: 7 }, (_, i) => dateKeyAdd(startKey, i));
}

/** Shifts a concrete shift by whole days — used by "copy last week". */
export function shiftByDays(
  shift: { staffId: string; startsAt: Date; endsAt: Date; note?: string | null },
  days: number,
): { staffId: string; startsAt: Date; endsAt: Date; note: string | null } {
  const delta = days * 86_400_000;
  return {
    staffId: shift.staffId,
    startsAt: new Date(shift.startsAt.getTime() + delta),
    endsAt: new Date(shift.endsAt.getTime() + delta),
    note: shift.note ?? null,
  };
}

/** `YYYY-MM-DD` default day key for `clinicDateKey` without importing twice. */
export function dayKeyOf(input: Date, timeZone: string): string {
  return clinicDateKey(input, timeZone);
}
