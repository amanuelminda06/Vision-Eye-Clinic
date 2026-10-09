import { describe, expect, it } from 'vitest';
import { combineDateAndTime } from '@/lib/datetime';
import { buildPublicDoctorStatus, isPublicDoctor, type PublicStaffInput } from './public-status';
import type { ShiftLike } from './shifts';
import type { LeaveLike } from './leave';

const TZ = 'Africa/Addis_Ababa';

function at(dateKey: string, time: string): Date {
  return combineDateAndTime(dateKey, time, TZ);
}

const doctor: PublicStaffInput = {
  id: 'doc-1',
  fullName: 'Dr. Abebe Kebede',
  specialty: 'Ophthalmology',
  photoUrl: '/api/uploads/doc-1.png',
  isDoctor: true,
  showOnWebsite: true,
  active: true,
};

const now = at('2026-10-08', '10:00');

describe('public doctor status', () => {
  it('publishes only flagged, active doctors', () => {
    expect(isPublicDoctor(doctor)).toBe(true);
    expect(isPublicDoctor({ ...doctor, active: false })).toBe(false);
    expect(isPublicDoctor({ ...doctor, showOnWebsite: false })).toBe(false);
    expect(isPublicDoctor({ ...doctor, isDoctor: false })).toBe(false);
  });

  it('reports ON_DUTY_NOW during a running shift', () => {
    const shifts: ShiftLike[] = [
      {
        id: 's1',
        staffId: 'doc-1',
        startsAt: at('2026-10-08', '09:00'),
        endsAt: at('2026-10-08', '17:00'),
      },
    ];
    const status = buildPublicDoctorStatus({
      staff: doctor,
      shifts,
      leaves: [],
      now,
      timeZone: TZ,
    });
    expect(status.status).toBe('ON_DUTY_NOW');
    expect(status.todayShifts).toEqual([{ start: '09:00', end: '17:00' }]);
    expect(status.nextShiftStartsAt).toBe(at('2026-10-08', '09:00').toISOString());
  });

  it('approved leave wins and marks the doctor ON_LEAVE even during a shift', () => {
    const shifts: ShiftLike[] = [
      {
        id: 's1',
        staffId: 'doc-1',
        startsAt: at('2026-10-08', '09:00'),
        endsAt: at('2026-10-08', '17:00'),
      },
    ];
    const leaves: LeaveLike[] = [
      {
        id: 'l1',
        staffId: 'doc-1',
        type: 'ANNUAL',
        status: 'APPROVED',
        startDate: '2026-10-08',
        endDate: '2026-10-08',
        halfDay: false,
      },
    ];
    const status = buildPublicDoctorStatus({ staff: doctor, shifts, leaves, now, timeZone: TZ });
    expect(status.status).toBe('ON_LEAVE');
  });

  it('reports NOT_ON_DUTY with the next shift when none is running', () => {
    const shifts: ShiftLike[] = [
      {
        id: 's1',
        staffId: 'doc-1',
        startsAt: at('2026-10-09', '09:00'),
        endsAt: at('2026-10-09', '17:00'),
      },
    ];
    const status = buildPublicDoctorStatus({
      staff: doctor,
      shifts,
      leaves: [],
      now,
      timeZone: TZ,
    });
    expect(status.status).toBe('NOT_ON_DUTY');
    expect(status.todayShifts).toEqual([]);
    expect(status.nextShiftStartsAt).toBe(at('2026-10-09', '09:00').toISOString());
  });

  it('never leaks private or internal fields', () => {
    const raw = {
      ...doctor,
      phone: '+251911223344',
      email: 'doctor@visioneye.et',
      annualLeaveDays: 20,
      jobTitle: 'Ophthalmologist',
      createdAt: new Date('2026-01-01T00:00:00Z'),
      secret: 'internal',
    };
    const leaves: LeaveLike[] = [
      {
        id: 'l1',
        staffId: 'doc-1',
        type: 'SICK',
        status: 'APPROVED',
        startDate: '2026-10-08',
        endDate: '2026-10-08',
        halfDay: false,
      },
    ];
    const status = buildPublicDoctorStatus({
      staff: raw as PublicStaffInput,
      shifts: [],
      leaves,
      now,
      timeZone: TZ,
    });
    const json = JSON.stringify(status);
    for (const forbidden of [
      'phone',
      '+251911223344',
      'email',
      'doctor@visioneye.et',
      'annualLeaveDays',
      'jobTitle',
      'secret',
      'internal',
    ]) {
      expect(json).not.toContain(forbidden);
    }
    expect(Object.keys(status).sort()).toEqual([
      'id',
      'name',
      'nextShiftStartsAt',
      'photoUrl',
      'specialty',
      'status',
      'todayShifts',
    ]);
  });
});
