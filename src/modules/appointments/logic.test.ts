import { describe, expect, it } from 'vitest';
import { combineDateAndTime, dateKeyAdd } from '@/lib/datetime';
import {
  appointmentCoverageWarnings,
  appointmentInstants,
  findDoctorDoubleBookings,
  followUpDueKey,
  isInsideShift,
  resolvePatientMatch,
  slotForTimeWindow,
  transitionAllowed,
  workingMinutesBetween,
  type AppointmentLike,
  type LeaveLike,
  type ShiftLike,
} from './logic';

const TZ = 'Africa/Addis_Ababa';

function at(dateKey: string, time: string): Date {
  return combineDateAndTime(dateKey, time, TZ);
}

function appt(
  over: Partial<AppointmentLike> & { id: string; doctorId: string; startsAt: Date; endsAt: Date },
): AppointmentLike {
  return { status: 'SCHEDULED', ...over };
}

describe('status transitions', () => {
  it('moves forward along SCHEDULED -> CONFIRMED -> COMPLETED', () => {
    expect(transitionAllowed('SCHEDULED', 'CONFIRMED')).toBe(true);
    expect(transitionAllowed('CONFIRMED', 'COMPLETED')).toBe(true);
    expect(transitionAllowed('SCHEDULED', 'COMPLETED')).toBe(false);
    expect(transitionAllowed('CONFIRMED', 'SCHEDULED')).toBe(false);
  });

  it('lets SCHEDULED/CONFIRMED cancel or no-show', () => {
    expect(transitionAllowed('SCHEDULED', 'CANCELLED')).toBe(true);
    expect(transitionAllowed('SCHEDULED', 'NO_SHOW')).toBe(true);
    expect(transitionAllowed('CONFIRMED', 'CANCELLED')).toBe(true);
    expect(transitionAllowed('CONFIRMED', 'NO_SHOW')).toBe(true);
  });

  it('rejects self-transitions and unknown statuses', () => {
    expect(transitionAllowed('CONFIRMED', 'CONFIRMED')).toBe(false);
    expect(transitionAllowed('NOPE', 'CONFIRMED')).toBe(false);
    expect(transitionAllowed('CONFIRMED', 'NOPE')).toBe(false);
  });

  it('terminal states are frozen unless the OWNER overrides', () => {
    for (const terminal of ['COMPLETED', 'NO_SHOW', 'CANCELLED'] as const) {
      expect(transitionAllowed(terminal, 'CONFIRMED')).toBe(false);
      expect(transitionAllowed(terminal, 'SCHEDULED')).toBe(false);
      expect(transitionAllowed(terminal, 'CONFIRMED', { ownerOverride: true })).toBe(true);
      expect(transitionAllowed(terminal, 'SCHEDULED', { ownerOverride: true })).toBe(true);
    }
  });
});

describe('doctor double-booking warning', () => {
  const existing: AppointmentLike[] = [
    appt({
      id: 'a1',
      doctorId: 'doc-1',
      status: 'CONFIRMED',
      startsAt: at('2026-10-08', '09:00'),
      endsAt: at('2026-10-08', '09:45'),
    }),
    appt({
      id: 'a2',
      doctorId: 'doc-1',
      status: 'CANCELLED',
      startsAt: at('2026-10-08', '10:00'),
      endsAt: at('2026-10-08', '10:45'),
    }),
    appt({
      id: 'a3',
      doctorId: 'doc-2',
      status: 'SCHEDULED',
      startsAt: at('2026-10-08', '09:00'),
      endsAt: at('2026-10-08', '09:45'),
    }),
  ];

  it('flags overlapping active appointments for the same doctor', () => {
    const clashes = findDoctorDoubleBookings(existing, {
      doctorId: 'doc-1',
      startsAt: at('2026-10-08', '09:15'),
      endsAt: at('2026-10-08', '10:30'),
    });
    expect(clashes.map((c) => c.id)).toEqual(['a1']);
  });

  it('ignores cancelled, other doctors and the record itself', () => {
    const clashes = findDoctorDoubleBookings(existing, {
      id: 'a1',
      doctorId: 'doc-1',
      startsAt: at('2026-10-08', '09:00'),
      endsAt: at('2026-10-08', '09:45'),
    });
    expect(clashes).toEqual([]);
  });

  it('treats adjacent windows as non-overlapping', () => {
    const clashes = findDoctorDoubleBookings(existing, {
      doctorId: 'doc-1',
      startsAt: at('2026-10-08', '09:45'),
      endsAt: at('2026-10-08', '10:45'),
    });
    expect(clashes).toEqual([]);
  });
});

describe('shift / leave coverage warnings', () => {
  const shifts: ShiftLike[] = [
    {
      id: 's1',
      staffId: 'doc-1',
      startsAt: at('2026-10-08', '08:00'),
      endsAt: at('2026-10-08', '17:00'),
    },
    {
      id: 's2',
      staffId: 'doc-1',
      startsAt: at('2026-10-08', '20:00'),
      endsAt: at('2026-10-09', '04:00'),
    },
  ];
  const leaves: LeaveLike[] = [
    {
      id: 'l1',
      staffId: 'doc-1',
      type: 'ANNUAL',
      status: 'APPROVED',
      startDate: '2026-10-09',
      endDate: '2026-10-09',
      halfDay: false,
    },
  ];

  it('no warnings when inside a shift and not on leave', () => {
    const warnings = appointmentCoverageWarnings(
      { doctorId: 'doc-1', startsAt: at('2026-10-08', '09:30'), endsAt: at('2026-10-08', '10:00') },
      shifts,
      leaves,
      TZ,
    );
    expect(warnings).toEqual([]);
  });

  it('warns when the window is outside any shift', () => {
    const warnings = appointmentCoverageWarnings(
      { doctorId: 'doc-1', startsAt: at('2026-10-08', '07:00'), endsAt: at('2026-10-08', '07:30') },
      shifts,
      leaves,
      TZ,
    );
    expect(warnings).toContain('outsideShift');
  });

  it('warns during approved leave even if a shift exists', () => {
    const warnings = appointmentCoverageWarnings(
      { doctorId: 'doc-1', startsAt: at('2026-10-09', '09:00'), endsAt: at('2026-10-09', '09:30') },
      shifts,
      leaves,
      TZ,
    );
    expect(warnings).toContain('onLeave');
  });

  it('handles an overnight shift as a covering window', () => {
    expect(isInsideShift(shifts, at('2026-10-08', '22:00'), at('2026-10-08', '23:30'))).toBe(true);
  });
});

describe('request conversion helpers', () => {
  it('matches an existing patient by exact E.164', () => {
    const patients = [
      { id: 'p1', phoneE164: '+251911223344' },
      { id: 'p2', phoneE164: '+251922334455' },
    ];
    expect(resolvePatientMatch(patients, '+251911223344')?.id).toBe('p1');
    expect(resolvePatientMatch(patients, '+251999999999')).toBeNull();
  });

  it('maps time windows to wall-clock slots with a sane default', () => {
    expect(slotForTimeWindow('morning')).toEqual({ start: '09:00', end: '10:00' });
    expect(slotForTimeWindow('AFTERNOON')).toEqual({ start: '14:00', end: '15:00' });
    expect(slotForTimeWindow('evening')).toEqual({ start: '16:00', end: '17:00' });
    expect(slotForTimeWindow(null)).toEqual({ start: '09:00', end: '10:00' });
    expect(slotForTimeWindow('unknown')).toEqual({ start: '09:00', end: '10:00' });
  });
});

describe('timezone handling', () => {
  it('builds UTC instants from clinic-local day + time (+03:00 Addis)', () => {
    const { startsAt, endsAt } = appointmentInstants('2026-10-08', '09:00', 30, TZ);
    expect(startsAt.toISOString()).toBe('2026-10-08T06:00:00.000Z');
    expect(endsAt.toISOString()).toBe('2026-10-08T06:30:00.000Z');
  });

  it('clinicDateKey round-trips across the overnight edge', () => {
    // 23:00 Addis = 20:00Z; +90 min = 21:30Z, which is 00:30 the next local day.
    const overnight = appointmentInstants('2026-10-08', '23:00', 90, TZ);
    expect(overnight.endsAt.toISOString()).toBe('2026-10-08T21:30:00.000Z');
  });
});

describe('follow-up due presets', () => {
  it('computes 2 weeks and calendar months, clamping month-end', () => {
    expect(followUpDueKey('2026-10-08', '2w')).toBe(dateKeyAdd('2026-10-08', 14));
    expect(followUpDueKey('2026-01-31', '1m')).toBe('2026-02-28');
    expect(followUpDueKey('2026-10-08', '3m')).toBe('2027-01-08');
    expect(followUpDueKey('2026-10-08', '6m')).toBe('2027-04-08');
    expect(followUpDueKey('2026-10-08', 'custom', '2026-12-01')).toBe('2026-12-01');
  });
});

describe('working minutes (request waiting time)', () => {
  const hours = {
    0: { open: '08:00', close: '12:00', closed: true },
    1: { open: '08:00', close: '17:00', closed: false },
    2: { open: '08:00', close: '17:00', closed: false },
    3: { open: '08:00', close: '17:00', closed: false },
    4: { open: '08:00', close: '17:00', closed: false },
    5: { open: '08:00', close: '17:00', closed: false },
    6: { open: '08:00', close: '12:00', closed: false },
  } as const;
  const grid = Object.keys(hours).map((k) => hours[Number(k) as keyof typeof hours]);

  it('counts only open-time minutes within a day', () => {
    expect(
      workingMinutesBetween(at('2026-10-05', '08:00'), at('2026-10-05', '10:00'), grid, TZ),
    ).toBe(120);
    expect(
      workingMinutesBetween(at('2026-10-05', '16:00'), at('2026-10-06', '09:00'), grid, TZ),
    ).toBe(120);
  });

  it('skips weekends', () => {
    // Fri 12:00 -> Mon 09:00: Fri 12-17 (300) + Sat 08-12 (240) + Mon 08-09 (60).
    expect(
      workingMinutesBetween(at('2026-10-02', '12:00'), at('2026-10-05', '09:00'), grid, TZ),
    ).toBe(600);
  });

  it('returns 0 for empty or backwards windows', () => {
    expect(
      workingMinutesBetween(at('2026-10-05', '10:00'), at('2026-10-05', '08:00'), grid, TZ),
    ).toBe(0);
    expect(
      workingMinutesBetween(at('2026-10-05', '08:00'), at('2026-10-05', '08:00'), grid, TZ),
    ).toBe(0);
  });
});
