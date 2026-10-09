import { describe, expect, it } from 'vitest';
import { combineDateAndTime } from '@/lib/datetime';
import {
  dateKeysBetween,
  findOverlappingShift,
  generateTemplateShifts,
  isOvernight,
  isShiftActiveAt,
  nextShiftAfter,
  shiftByDays,
  shiftInstantsForDay,
  shiftsOverlap,
  weekDays,
  weekStartKey,
  weekdayOf,
  type TemplateInput,
} from './shifts';

const TZ = 'Africa/Addis_Ababa';

function at(dateKey: string, time: string): Date {
  return combineDateAndTime(dateKey, time, TZ);
}

describe('shift geometry', () => {
  it('detects overnight templates (end <= start)', () => {
    expect(isOvernight('22:00', '06:00')).toBe(true);
    expect(isOvernight('06:00', '22:00')).toBe(false);
    expect(isOvernight('09:00', '09:00')).toBe(true);
  });

  it('places an overnight shift on the next day and spans 8h', () => {
    const { startsAt, endsAt } = shiftInstantsForDay(
      { startTime: '22:00', endTime: '06:00' },
      '2026-10-08',
      TZ,
    );
    expect(startsAt.toISOString()).toBe(at('2026-10-08', '22:00').toISOString());
    expect(endsAt.toISOString()).toBe(at('2026-10-09', '06:00').toISOString());
    expect(endsAt.getTime() - startsAt.getTime()).toBe(8 * 3600 * 1000);
  });

  it('keeps a same-day shift on the same day', () => {
    const { startsAt, endsAt } = shiftInstantsForDay(
      { startTime: '09:00', endTime: '17:00' },
      '2026-10-08',
      TZ,
    );
    expect(endsAt.getTime() - startsAt.getTime()).toBe(8 * 3600 * 1000);
  });

  it('overlap is symmetric and half-open (touching edges do not overlap)', () => {
    const a = { s: at('2026-10-08', '09:00'), e: at('2026-10-08', '12:00') };
    const b = { s: at('2026-10-08', '12:00'), e: at('2026-10-08', '15:00') };
    const c = { s: at('2026-10-08', '11:00'), e: at('2026-10-08', '13:00') };
    expect(shiftsOverlap(a.s, a.e, b.s, b.e)).toBe(false);
    expect(shiftsOverlap(a.s, a.e, c.s, c.e)).toBe(true);
    expect(shiftsOverlap(c.s, c.e, a.s, a.e)).toBe(true);
  });

  it('finds an overlapping shift for the same person only', () => {
    const existing = [
      {
        id: 's1',
        staffId: 'p1',
        startsAt: at('2026-10-08', '09:00'),
        endsAt: at('2026-10-08', '17:00'),
      },
      {
        id: 's2',
        staffId: 'p2',
        startsAt: at('2026-10-08', '09:00'),
        endsAt: at('2026-10-08', '17:00'),
      },
    ];
    const clash = findOverlappingShift(existing, {
      staffId: 'p1',
      startsAt: at('2026-10-08', '16:00'),
      endsAt: at('2026-10-08', '20:00'),
    });
    expect(clash?.id).toBe('s1');
    expect(
      findOverlappingShift(existing, {
        staffId: 'p3',
        startsAt: at('2026-10-08', '16:00'),
        endsAt: at('2026-10-08', '20:00'),
      }),
    ).toBeUndefined();
  });

  it('ignores the shift being edited (ignoreId)', () => {
    const existing = [
      {
        id: 's1',
        staffId: 'p1',
        startsAt: at('2026-10-08', '09:00'),
        endsAt: at('2026-10-08', '17:00'),
      },
    ];
    expect(
      findOverlappingShift(existing, {
        staffId: 'p1',
        startsAt: at('2026-10-08', '10:00'),
        endsAt: at('2026-10-08', '18:00'),
        ignoreId: 's1',
      }),
    ).toBeUndefined();
  });

  it('detects active and next shifts', () => {
    const shift = {
      id: 's1',
      staffId: 'p1',
      startsAt: at('2026-10-08', '09:00'),
      endsAt: at('2026-10-08', '17:00'),
    };
    expect(isShiftActiveAt(shift, at('2026-10-08', '10:00'))).toBe(true);
    expect(isShiftActiveAt(shift, at('2026-10-08', '17:00'))).toBe(false);
    const later = {
      id: 's2',
      staffId: 'p1',
      startsAt: at('2026-10-09', '09:00'),
      endsAt: at('2026-10-09', '17:00'),
    };
    expect(nextShiftAfter([shift, later], at('2026-10-08', '10:00'))?.startsAt).toEqual(
      later.startsAt,
    );
    expect(nextShiftAfter([shift], at('2026-10-08', '18:00'))).toBeNull();
  });
});

describe('template generation', () => {
  const thursday = weekdayOf('2026-10-08');
  const templates: TemplateInput[] = [
    {
      id: 't1',
      staffId: 'p1',
      weekday: thursday,
      startTime: '09:00',
      endTime: '17:00',
      validFrom: '2026-10-01',
      validTo: null,
    },
    {
      id: 't2',
      staffId: 'p1',
      weekday: thursday,
      startTime: '20:00',
      endTime: '04:00',
      validFrom: '2026-10-01',
      validTo: null,
    },
  ];

  it('generates matching weekdays across the range, including overnight', () => {
    const shifts = generateTemplateShifts(templates, '2026-10-08', '2026-10-21', TZ);
    expect(shifts).toHaveLength(4);
    const overnight = shifts.find((shift) => shift.templateId === 't2')!;
    expect(overnight.startsAt.toISOString()).toBe(at('2026-10-08', '20:00').toISOString());
    expect(overnight.endsAt.toISOString()).toBe(at('2026-10-09', '04:00').toISOString());
  });

  it('respects validFrom/validTo windows', () => {
    const late: TemplateInput[] = [
      { ...templates[0], validFrom: '2026-10-10', validTo: '2026-10-20' },
    ];
    const shifts = generateTemplateShifts(late, '2026-10-08', '2026-10-21', TZ);
    // only 2026-10-15 is a Thursday within [10, 20]
    expect(shifts).toHaveLength(1);
    expect(shifts[0].dateKey).toBe('2026-10-15');
  });

  it('builds inclusive day lists and Monday-based weeks', () => {
    expect(dateKeysBetween('2026-10-08', '2026-10-10')).toEqual([
      '2026-10-08',
      '2026-10-09',
      '2026-10-10',
    ]);
    expect(weekStartKey('2026-10-08')).toBe('2026-10-05'); // Thursday -> Monday
    expect(weekDays('2026-10-05')).toHaveLength(7);
    expect(weekDays('2026-10-05')[6]).toBe('2026-10-11');
  });

  it('shiftByDays advances an overnight shift by 7 days', () => {
    const shifted = shiftByDays(
      { staffId: 'p1', startsAt: at('2026-10-08', '22:00'), endsAt: at('2026-10-09', '06:00') },
      7,
    );
    expect(shifted.startsAt.toISOString()).toBe(at('2026-10-15', '22:00').toISOString());
    expect(shifted.endsAt.toISOString()).toBe(at('2026-10-16', '06:00').toISOString());
  });
});
