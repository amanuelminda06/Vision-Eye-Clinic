import { describe, expect, it } from 'vitest';
import { combineDateAndTime } from '@/lib/datetime';
import { buildPublicDoctorStatus, type PublicStaffInput } from '@/modules/hr/public-status';
import {
  FORBIDDEN_PUBLIC_FIELDS,
  PUBLIC_DOCTOR_FIELDS,
  PUBLIC_SERVICE_FIELDS,
  findForbiddenFields,
  toPublicService,
} from './projection';

describe('public projection allow-lists', () => {
  it('maps a DB service row to the public shape and drops the price', () => {
    const row = {
      id: 'svc-1',
      name: 'Eye examination',
      nameEn: 'Eye examination',
      nameAm: 'የዓይን ምርመራ',
      category: 'consultation',
      displayOrder: 2,
      imageUrl: '/services/eye-examination-glasses.png',
      publicDescriptionEn: 'A thorough exam.',
      publicDescriptionAm: 'ጥልቅ ምርመራ።',
      // Extra private columns that must never survive the projection:
      currentPrice: '500.00',
      price: '500.00',
    };
    const publicService = toPublicService(row);
    expect(Object.keys(publicService).sort()).toEqual([...PUBLIC_SERVICE_FIELDS].sort());
    expect(findForbiddenFields(publicService).size).toBe(0);
    expect(JSON.stringify(publicService)).not.toContain('500');
  });

  it('detects forbidden fields anywhere in a nested payload', () => {
    const found = findForbiddenFields({
      doctors: [{ id: 'a', phone: '+2519', nested: { salary: 1 } }],
      price: '10',
    });
    expect(found).toEqual(new Set(['phone', 'salary', 'price']));
  });

  it('the public doctor feed never contains a forbidden field', () => {
    const staff: PublicStaffInput & { phone?: string; email?: string } = {
      id: 'doc-1',
      fullName: 'Dr. Abebe',
      specialty: 'Ophthalmology',
      photoUrl: null,
      isDoctor: true,
      showOnWebsite: true,
      active: true,
      phone: '+251911111111',
      email: 'doc@example.com',
    };
    const tz = 'Africa/Addis_Ababa';
    const doctor = buildPublicDoctorStatus({
      staff,
      shifts: [
        {
          id: 's1',
          staffId: 'doc-1',
          startsAt: combineDateAndTime('2026-10-08', '09:00', tz),
          endsAt: combineDateAndTime('2026-10-08', '17:00', tz),
        },
      ],
      leaves: [],
      now: combineDateAndTime('2026-10-08', '10:00', tz),
      timeZone: tz,
    });

    expect(Object.keys(doctor).sort()).toEqual([...PUBLIC_DOCTOR_FIELDS].sort());
    expect(findForbiddenFields(doctor).size).toBe(0);
  });

  it('keeps the forbidden-field list free of public allow-listed names', () => {
    const allowed = new Set<string>([...PUBLIC_SERVICE_FIELDS, ...PUBLIC_DOCTOR_FIELDS]);
    for (const field of FORBIDDEN_PUBLIC_FIELDS) {
      expect(allowed.has(field)).toBe(false);
    }
  });
});
