import type { PrismaClient } from '@prisma/client';
import {
  clinicDateKey,
  clinicTimeKey,
  combineDateAndTime,
  dateKeyAdd,
  DEFAULT_TIMEZONE,
} from '../src/lib/datetime';

/**
 * Appointments + follow-ups demo data: 20 appointments across the next 7 days
 * (placed inside doctor shifts, skipping Sundays and approved leave) and 5
 * follow-ups (overdue / upcoming / done). Deterministic and idempotent — the
 * seed records carry `seed-*` ids and are replaced on every run.
 */

type Patient = { id: string; fullName: string; phoneE164: string };

function weekdayOf(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}

/** Latest weekday <= `dateKey` (skips Sundays, the clinic's closed day). */
function latestWeekday(dateKey: string): string {
  let cursor = dateKey;
  for (let guard = 0; guard < 8; guard++) {
    if (weekdayOf(cursor) !== 0) return cursor;
    cursor = dateKeyAdd(cursor, -1);
  }
  return dateKey;
}

export async function seedAppointments(prisma: PrismaClient): Promise<void> {
  const setting = await prisma.setting.findUnique({ where: { key: 'timezone' } });
  const tz = typeof setting?.value === 'string' && setting.value ? setting.value : DEFAULT_TIMEZONE;
  const todayKey = clinicDateKey(new Date(), tz);

  const owner = await prisma.user.findFirst({
    where: { role: 'OWNER' },
    orderBy: { createdAt: 'asc' },
    select: { id: true },
  });
  const creator = owner?.id ?? 'seed';

  const [doctors, patients, services] = await Promise.all([
    prisma.staff.findMany({ where: { isDoctor: true, active: true }, select: { id: true } }),
    prisma.patient.findMany({ where: { active: true }, orderBy: { fullName: 'asc' } }),
    prisma.service.findMany({ where: { active: true }, select: { id: true, name: true } }),
  ]);

  if (doctors.length === 0 || patients.length === 0) {
    console.log('⚠ seed-appointments skipped: need at least one doctor and one patient');
    return;
  }

  const patientList: Patient[] = patients.map((p) => ({
    id: p.id,
    fullName: p.fullName,
    phoneE164: p.phoneE164,
  }));

  // Replace the seed records on every run (keeps counts stable + idempotent).
  await prisma.followUp.deleteMany({ where: { id: { startsWith: 'seed-followup-' } } });
  await prisma.appointment.deleteMany({ where: { id: { startsWith: 'seed-appt-' } } });

  // Approved leave for doctors → skip those dates.
  const approved = await prisma.leaveRequest.findMany({
    where: { status: 'APPROVED', staffId: { in: doctors.map((d) => d.id) } },
    select: { staffId: true, startDate: true, endDate: true },
  });
  const leaveKeys = new Set<string>();
  for (const leave of approved) {
    const startKey = String(leave.startDate).slice(0, 10);
    const endKey = String(leave.endDate).slice(0, 10);
    let cursor = startKey;
    for (let guard = 0; guard < 400 && cursor <= endKey; guard++) {
      leaveKeys.add(`${leave.staffId}:${cursor}`);
      cursor = dateKeyAdd(cursor, 1);
    }
  }

  // Doctor shifts overlapping the next 7 days → realistic slot placement.
  const from = combineDateAndTime(todayKey, '00:00', tz);
  const to = combineDateAndTime(dateKeyAdd(todayKey, 7), '00:00', tz);
  const shifts = await prisma.shift.findMany({
    where: {
      staffId: { in: doctors.map((d) => d.id) },
      startsAt: { lt: to },
      endsAt: { gt: from },
    },
    select: { staffId: true, startsAt: true, endsAt: true },
  });

  const created: Array<{ id: string; atKey: string; order: number }> = [];

  async function createAppointment(opts: {
    id: string;
    doctorId: string;
    atKey: string;
    startTime: string;
    durationMin: number;
    status: string;
    cancelReason?: string | null;
    note?: string | null;
  }) {
    const startsAt = combineDateAndTime(opts.atKey, opts.startTime, tz);
    const endsAt = new Date(startsAt.getTime() + opts.durationMin * 60_000);
    const service = services.length ? services[created.length % services.length] : null;
    await prisma.appointment.create({
      data: {
        id: opts.id,
        patientId: patientList[created.length % patientList.length].id,
        doctorId: opts.doctorId,
        serviceId: service?.id ?? null,
        startsAt,
        endsAt,
        status: opts.status as 'SCHEDULED' | 'CONFIRMED' | 'COMPLETED' | 'NO_SHOW' | 'CANCELLED',
        cancelReason: opts.cancelReason ?? null,
        notes: opts.note ?? null,
        createdFrom: 'STAFF',
        createdBy: creator,
      },
    });
    created.push({ id: opts.id, atKey: opts.atKey, order: created.length });
  }

  // 1. Recent-status appointments (demo variety on the last working days).
  {
    const d1 = doctors[0].id;
    const pastKey = latestWeekday(dateKeyAdd(todayKey, -2));
    const pastKey2 = latestWeekday(dateKeyAdd(pastKey, -1));
    await createAppointment({
      id: 'seed-appt-past-completed',
      doctorId: d1,
      atKey: pastKey,
      startTime: '09:30',
      durationMin: 30,
      status: 'COMPLETED',
      note: 'Routine check-up completed',
    });
    await createAppointment({
      id: 'seed-appt-past-no-show',
      doctorId: doctors.length > 1 ? doctors[1].id : d1,
      atKey: pastKey,
      startTime: '11:00',
      durationMin: 30,
      status: 'NO_SHOW',
      note: 'Did not arrive',
    });
    await createAppointment({
      id: 'seed-appt-past-cancelled',
      doctorId: d1,
      atKey: pastKey2,
      startTime: '10:00',
      durationMin: 30,
      status: 'CANCELLED',
      cancelReason: 'Patient requested to reschedule',
    });
  }

  // 2. Upcoming appointments — 17 slots, one per candidate (shifts only).
  {
    let placed = 0;
    const SLOT_OFFSETS = [60, 150] as const;
    for (let dayIndex = 0; dayIndex < 7 && placed < 17; dayIndex++) {
      const atKey = dateKeyAdd(todayKey, dayIndex);
      if (weekdayOf(atKey) === 0) continue; // Sundays closed
      for (const doctor of doctors) {
        if (placed >= 17) break;
        const eligible = shifts.filter((s) => {
          const key = clinicDateKey(s.startsAt, tz);
          return (
            s.staffId === doctor.id && key === atKey && !leaveKeys.has(`${doctor.id}:${atKey}`)
          );
        });
        if (eligible.length === 0) continue;
        const shift = eligible.reduce((a, b) => (a.startsAt <= b.startsAt ? a : b));
        const [sh, sm] = clinicTimeKey(shift.startsAt, tz).split(':').map(Number);
        for (const offset of SLOT_OFFSETS) {
          if (placed >= 17) break;
          const startMinutes = sh * 60 + sm + offset;
          const durationMin = placed % 2 === 0 ? 30 : 60;
          const pad = (n: number) => String(n).padStart(2, '0');
          const startTime = `${pad(Math.floor((startMinutes % 1440) / 60))}:${pad(startMinutes % 60)}`;
          const number = String(placed + 1).padStart(2, '0');
          await createAppointment({
            id: `seed-appt-f-${number}`,
            doctorId: doctor.id,
            atKey,
            startTime,
            durationMin,
            status: dayIndex < 2 ? 'CONFIRMED' : 'SCHEDULED',
          });
          placed += 1;
        }
      }
    }
  }

  console.log(`✓ appointments (${created.length})`);

  // 3. Follow-ups — 3 open (2 overdue), 1 due soon, 1 done.
  const completedAppt = await prisma.appointment.findUnique({
    where: { id: 'seed-appt-past-completed' },
    select: { id: true },
  });
  const followUpAt = (dateKey: string) => new Date(`${dateKey}T00:00:00.000Z`);

  const followUps = [
    {
      id: 'seed-followup-1',
      patientId: patientList[0].id,
      dueDate: followUpAt(dateKeyAdd(todayKey, -2)),
      reason: 'Repeat eye test',
      status: 'OPEN',
      createdFromAppointmentId: null,
    },
    {
      id: 'seed-followup-2',
      patientId: patientList[1].id,
      dueDate: followUpAt(dateKeyAdd(todayKey, -5)),
      reason: 'Glasses follow-up',
      status: 'OPEN',
      createdFromAppointmentId: null,
    },
    {
      id: 'seed-followup-3',
      patientId: patientList[2] ? patientList[2].id : patientList[0].id,
      dueDate: followUpAt(dateKeyAdd(todayKey, 7)),
      reason: '3-month check',
      status: 'OPEN',
      createdFromAppointmentId: null,
    },
    {
      id: 'seed-followup-4',
      patientId: patientList[3] ? patientList[3].id : patientList[1].id,
      dueDate: followUpAt(dateKeyAdd(todayKey, 14)),
      reason: 'Contact lens review',
      status: 'OPEN',
      createdFromAppointmentId: null,
    },
    {
      id: 'seed-followup-5',
      patientId: patientList[0].id,
      dueDate: followUpAt(dateKeyAdd(todayKey, -10)),
      reason: 'Reviewed after appointment',
      status: 'DONE',
      createdFromAppointmentId: completedAppt?.id ?? null,
    },
  ];

  for (const row of followUps) {
    await prisma.followUp.create({
      data: {
        ...row,
        createdBy: creator,
        status: row.status as 'OPEN' | 'DONE' | 'CANCELLED',
      },
    });
  }
  console.log(`✓ follow-ups (${followUps.length})`);
}
