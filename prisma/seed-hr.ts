import type { PrismaClient } from '@prisma/client';
import {
  clinicDateKey,
  combineDateAndTime,
  dateKeyAdd,
  DEFAULT_TIMEZONE,
} from '../src/lib/datetime';

/**
 * HR demo data: 3 doctors + 3 other staff, two weeks of shifts, a couple of
 * weekly templates and two leave requests. Deterministic and idempotent —
 * each block only writes when its table is empty, except staff which upserts.
 */

type StaffSeed = {
  id: string;
  fullName: string;
  jobTitle: string;
  isDoctor: boolean;
  specialty: string | null;
  showOnWebsite: boolean;
  annualLeaveDays: number | null;
};

export const HR_STAFF: StaffSeed[] = [
  {
    id: 'staff-doctor-2',
    fullName: 'Dr. Tigist Alemu',
    jobTitle: 'Ophthalmologist',
    isDoctor: true,
    specialty: 'Ophthalmology',
    showOnWebsite: true,
    annualLeaveDays: 20,
  },
  {
    id: 'staff-doctor-3',
    fullName: 'Dr. Dawit Bekele',
    jobTitle: 'Optometrist',
    isDoctor: true,
    specialty: 'Optometry',
    showOnWebsite: true,
    annualLeaveDays: 18,
  },
  {
    id: 'staff-nurse-1',
    fullName: 'Hanna Girma',
    jobTitle: 'Ophthalmic Nurse',
    isDoctor: false,
    specialty: null,
    showOnWebsite: false,
    annualLeaveDays: 22,
  },
  {
    id: 'staff-reception-1',
    fullName: 'Sara Tesfaye',
    jobTitle: 'Front Desk',
    isDoctor: false,
    specialty: null,
    showOnWebsite: false,
    annualLeaveDays: 20,
  },
  {
    id: 'staff-tech-1',
    fullName: 'Yonas Haile',
    jobTitle: 'Imaging Technician',
    isDoctor: false,
    specialty: null,
    showOnWebsite: false,
    annualLeaveDays: null,
  },
];

/** Weekday (0 = Sunday) of a `YYYY-MM-DD` key. */
function weekdayOf(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}

/** Monday-based start of the week containing `dateKey`. */
function weekStartKey(dateKey: string): string {
  return dateKeyAdd(dateKey, -((weekdayOf(dateKey) + 6) % 7));
}

type ShiftPattern = { staffId: string; start: string; end: string };

const STAFF_SHIFTS: ShiftPattern[] = [
  { staffId: 'staff-doctor-1', start: '08:00', end: '16:00' },
  { staffId: 'staff-doctor-2', start: '10:00', end: '18:00' },
  { staffId: 'staff-doctor-3', start: '08:00', end: '14:00' },
  { staffId: 'staff-nurse-1', start: '08:00', end: '16:00' },
  { staffId: 'staff-reception-1', start: '08:00', end: '17:00' },
  { staffId: 'staff-tech-1', start: '09:00', end: '17:00' },
];

export async function seedHr(prisma: PrismaClient): Promise<void> {
  const setting = await prisma.setting.findUnique({ where: { key: 'timezone' } });
  const tz = typeof setting?.value === 'string' && setting.value ? setting.value : DEFAULT_TIMEZONE;

  // 1. Staff (upsert — safe to re-run).
  for (const seed of HR_STAFF) {
    const data = {
      fullName: seed.fullName,
      jobTitle: seed.jobTitle,
      isDoctor: seed.isDoctor,
      specialty: seed.specialty,
      showOnWebsite: seed.showOnWebsite,
      annualLeaveDays: seed.annualLeaveDays,
      active: true,
    };
    await prisma.staff.upsert({
      where: { id: seed.id },
      update: data,
      create: { id: seed.id, ...data },
    });
  }
  await prisma.staff.update({
    where: { id: 'staff-doctor-1' },
    data: { annualLeaveDays: 20 },
  });
  console.log(`✓ HR staff (${HR_STAFF.length + 1})`);

  const owner = await prisma.user.findFirst({
    where: { role: 'OWNER' },
    orderBy: { createdAt: 'asc' },
    select: { id: true },
  });
  const creator = owner?.id ?? 'seed';

  const todayKey = clinicDateKey(new Date(), tz);
  const startKey = weekStartKey(todayKey);

  // 2. Weekly templates (only when none exist).
  const templateCount = await prisma.shiftTemplate.count();
  if (templateCount === 0) {
    const validFrom = new Date(`${startKey}T00:00:00.000Z`);
    await prisma.shiftTemplate.createMany({
      data: [
        { staffId: 'staff-doctor-1', weekday: 1, startTime: '08:00', endTime: '16:00', validFrom },
        { staffId: 'staff-doctor-1', weekday: 3, startTime: '08:00', endTime: '16:00', validFrom },
        { staffId: 'staff-doctor-2', weekday: 2, startTime: '10:00', endTime: '18:00', validFrom },
        { staffId: 'staff-tech-1', weekday: 5, startTime: '20:00', endTime: '04:00', validFrom },
      ],
    });
    console.log('✓ HR shift templates (4)');
  } else {
    console.log(`✓ HR shift templates already seeded (${templateCount}) — skipped`);
  }

  // 3. Two weeks of shifts (only when none exist).
  const shiftCount = await prisma.shift.count();
  if (shiftCount === 0) {
    const rows: Array<{
      staffId: string;
      startsAt: Date;
      endsAt: Date;
      source: 'TEMPLATE' | 'MANUAL';
      createdBy: string;
    }> = [];

    for (let dayIndex = 0; dayIndex < 14; dayIndex++) {
      const dateKey = dateKeyAdd(startKey, dayIndex);
      const weekday = weekdayOf(dateKey);
      if (weekday === 0) continue; // closed on Sundays

      for (const pattern of STAFF_SHIFTS) {
        const overnight = pattern.staffId === 'staff-tech-1' && weekday === 5;
        const start = overnight ? '20:00' : pattern.start;
        const end = overnight ? '04:00' : pattern.end;
        const startsAt = combineDateAndTime(dateKey, start, tz);
        const endKey = end <= start ? dateKeyAdd(dateKey, 1) : dateKey;
        const endsAt = combineDateAndTime(endKey, end, tz);
        rows.push({
          staffId: pattern.staffId,
          startsAt,
          endsAt,
          source: 'MANUAL',
          createdBy: creator,
        });
      }
    }

    await prisma.shift.createMany({ data: rows });
    console.log(`✓ HR shifts (${rows.length}) over two weeks`);
  } else {
    console.log(`✓ HR shifts already seeded (${shiftCount}) — skipped`);
  }

  // 4. Two leave requests (only when none exist).
  const leaveCount = await prisma.leaveRequest.count();
  if (leaveCount === 0) {
    const approvedStartKey = dateKeyAdd(startKey, 8);
    const approvedEndKey = dateKeyAdd(startKey, 9);
    await prisma.leaveRequest.create({
      data: {
        staffId: 'staff-doctor-2',
        type: 'ANNUAL',
        startDate: new Date(`${approvedStartKey}T00:00:00.000Z`),
        endDate: new Date(`${approvedEndKey}T00:00:00.000Z`),
        halfDay: false,
        reason: 'Family event',
        status: 'APPROVED',
        decidedBy: owner?.id ?? null,
        decidedAt: new Date(),
        decisionNote: 'Approved',
        createdBy: creator,
      },
    });
    await prisma.leaveRequest.create({
      data: {
        staffId: 'staff-nurse-1',
        type: 'SICK',
        startDate: new Date(`${dateKeyAdd(startKey, 2)}T00:00:00.000Z`),
        endDate: new Date(`${dateKeyAdd(startKey, 3)}T00:00:00.000Z`),
        halfDay: false,
        reason: 'Unwell',
        status: 'REQUESTED',
        createdBy: creator,
      },
    });
    console.log('✓ HR leave requests (2)');
  } else {
    console.log(`✓ HR leave requests already seeded (${leaveCount}) — skipped`);
  }
}
