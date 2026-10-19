'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { clinicDateKey, combineDateAndTime, dateKeyAdd } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { normalizePhone } from '@/lib/phone';
import { APPT_ROLES, FOLLOW_UP_ROLES } from './roles';
import {
  appointmentFormSchema,
  appointmentStatusSchema,
  followUpFormSchema,
  followUpStatusSchema,
  type AppointmentFormValues,
} from './schemas';
import {
  appointmentCoverageWarnings,
  appointmentInstants,
  findDoctorDoubleBookings,
  followUpDueKey,
  isTerminalStatus,
  slotForTimeWindow,
  transitionAllowed,
} from './logic';
import { getApprovedLeaveInRange, getDoctorShiftsForCoverage, listAppointments } from './queries';
import { onAppointmentChanged, onFollowUpChanged } from '@/modules/messaging';
import {
  DEFAULT_DURATION,
  FOLLOW_UP_PRESETS,
  MAX_APPT_NOTES,
  MAX_REQUEST_DATE_DAYS_AHEAD,
} from './constants';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function timezone(): Promise<string> {
  const settings = await getSettingsMap();
  return timezoneFrom(settings);
}

/** Patient picker: reuse an existing patient, or quick-create one (SMS opt-out). */
async function resolvePatient(
  actorId: string,
  values: AppointmentFormValues,
): Promise<{ id: string } | { error: string }> {
  if (values.patientId) {
    const patient = await prisma.patient.findUnique({
      where: { id: values.patientId },
      select: { id: true, active: true },
    });
    if (!patient || !patient.active) return { error: 'patientRequired' };
    return { id: patient.id };
  }
  const name = (values.quickName ?? '').trim();
  const phone = normalizePhone(values.quickPhone ?? '');
  if (!name || !phone) return { error: 'patientRequired' };
  const existing = await prisma.patient.findUnique({
    where: { phoneE164: phone },
    select: { id: true, active: true },
  });
  if (existing) {
    if (!existing.active) return { error: 'patientInactive' };
    return { id: existing.id };
  }
  const created = await prisma.patient.create({
    data: {
      fullName: name,
      phoneE164: phone,
      createdBy: actorId,
    },
    select: { id: true },
  });
  return { id: created.id };
}

/** Double-booking + shift/leave advisories; audits an explicit override. */
async function schedulingWarnings(
  actor: { id: string },
  doctorId: string,
  dateKey: string,
  startsAt: Date,
  endsAt: Date,
  tz: string,
  override: boolean,
  candidateId?: string,
): Promise<{ warnings: string[]; doubleBookings: number }> {
  const [existing, shifts, leaves] = await Promise.all([
    listAppointments({ doctorId, from: startsAt, to: endsAt }),
    getDoctorShiftsForCoverage(doctorId, startsAt, endsAt),
    getApprovedLeaveInRange([doctorId], dateKey, dateKey),
  ]);

  const doubleBookings = findDoctorDoubleBookings(existing, {
    id: candidateId,
    doctorId,
    startsAt,
    endsAt,
  });
  const coverage = appointmentCoverageWarnings({ doctorId, startsAt, endsAt }, shifts, leaves, tz);

  const warnings = [
    ...(doubleBookings.length > 0 ? (['doubleBooking'] as const) : []),
    ...coverage,
  ];

  if (override && doubleBookings.length > 0) {
    await writeAudit({
      user: actor,
      entity: 'Appointment',
      entityId: candidateId ?? '',
      action: 'APPOINTMENT_DOUBLE_BOOKED',
      new: {
        doctorId,
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
        clashCount: doubleBookings.length,
      },
    });
  }

  return { warnings, doubleBookings: doubleBookings.length };
}

async function messageForAppointment(appointmentId: string, previousStartsAt?: Date | null) {
  await onAppointmentChanged({ appointmentId, previousStartsAt });
}

async function messageForFollowUp(followUpId: string) {
  await onFollowUpChanged({ followUpId });
}

// ---------------------------------------------------------------------------
// Appointments
// ---------------------------------------------------------------------------

export async function saveAppointmentAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string; warnings: string[] }>> {
  return withAuth<{ id: string; warnings: string[] }>(APPT_ROLES, async (actor) => {
    const values = validate(appointmentFormSchema, {
      id: formData.get('id') ?? undefined,
      patientId: formData.get('patientId') ?? undefined,
      quickName: formData.get('quickName') ?? undefined,
      quickPhone: formData.get('quickPhone') ?? undefined,
      doctorId: formData.get('doctorId') ?? '',
      dateKey: formData.get('dateKey') ?? '',
      startTime: formData.get('startTime') ?? '',
      durationMin: formData.get('durationMin') ?? '30',
      serviceId: formData.get('serviceId') ?? undefined,
      notes: formData.get('notes') ?? undefined,
      overrideDoubleBooking: formData.get('overrideDoubleBooking') ?? false,
    });

    const doctor = await prisma.staff.findUnique({
      where: { id: values.doctorId },
      select: { id: true, active: true, isDoctor: true, fullName: true },
    });
    if (!doctor || !doctor.active || !doctor.isDoctor) return fail('doctorRequired');

    const existing = values.id
      ? await prisma.appointment.findUnique({
          where: { id: values.id },
          select: {
            id: true,
            status: true,
            patientId: true,
            doctorId: true,
            serviceId: true,
            startsAt: true,
            endsAt: true,
          },
        })
      : null;
    if (values.id && !existing) return fail('notFound');
    if (existing && isTerminalStatus(existing.status) && actor.role !== 'OWNER') {
      return fail('terminalLocked');
    }

    const tz = await timezone();
    const { startsAt, endsAt } = appointmentInstants(
      values.dateKey,
      values.startTime,
      values.durationMin,
      tz,
    );

    const patient = await resolvePatient(actor.id, values);
    if ('error' in patient) return fail(patient.error);

    const schedule = await schedulingWarnings(
      actor,
      doctor.id,
      values.dateKey,
      startsAt,
      endsAt,
      tz,
      values.overrideDoubleBooking,
      values.id,
    );

    if (!existing) {
      const appointment = await prisma.appointment.create({
        data: {
          patientId: patient.id,
          doctorId: doctor.id,
          startsAt,
          endsAt,
          serviceId: values.serviceId || null,
          notes: values.notes || null,
          status: 'SCHEDULED',
          createdFrom: 'STAFF',
          createdBy: actor.id,
        },
        select: { id: true },
      });
      await writeAudit({
        user: actor,
        entity: 'Appointment',
        entityId: appointment.id,
        action: 'APPOINTMENT_CREATED',
        new: {
          patientId: patient.id,
          doctorId: doctor.id,
          startsAt: startsAt.toISOString(),
          endsAt: endsAt.toISOString(),
          serviceId: values.serviceId || null,
          notes: values.notes || null,
          warnings: schedule.warnings,
        },
      });
      await messageForAppointment(appointment.id);
      return ok({ id: appointment.id, warnings: schedule.warnings });
    }

    const updated = await prisma.appointment.update({
      where: { id: existing.id },
      data: {
        patientId: patient.id,
        doctorId: doctor.id,
        startsAt,
        endsAt,
        serviceId: values.serviceId || null,
        notes: values.notes || null,
      },
      select: { id: true },
    });
    await writeAudit({
      user: actor,
      entity: 'Appointment',
      entityId: updated.id,
      action: 'APPOINTMENT_UPDATED',
      old: {
        patientId: existing.patientId,
        doctorId: existing.doctorId,
        serviceId: existing.serviceId,
        startsAt: existing.startsAt.toISOString(),
        endsAt: existing.endsAt.toISOString(),
      },
      new: {
        patientId: patient.id,
        doctorId: doctor.id,
        serviceId: values.serviceId || null,
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
      },
    });
    await messageForAppointment(updated.id, existing.startsAt);
    return ok({ id: updated.id, warnings: schedule.warnings });
  });
}

export async function setAppointmentStatusAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(APPT_ROLES, async (actor) => {
    const values = validate(appointmentStatusSchema, {
      id: formData.get('id') ?? '',
      status: formData.get('status') ?? '',
      cancelReason: formData.get('cancelReason') ?? undefined,
    });

    const appointment = await prisma.appointment.findUnique({
      where: { id: values.id },
      select: { id: true, status: true },
    });
    if (!appointment) return fail('notFound');

    const allowed = transitionAllowed(appointment.status, values.status, {
      ownerOverride: actor.role === 'OWNER',
    });
    if (!allowed) {
      if (isTerminalStatus(appointment.status) && actor.role !== 'OWNER')
        return fail('terminalLocked');
      return fail('invalidTransition');
    }
    if (values.status === 'CANCELLED' && !(values.cancelReason ?? '').trim()) {
      return fail('cancelReasonRequired');
    }

    const updated = await prisma.appointment.update({
      where: { id: appointment.id },
      data: {
        status: values.status as never,
        cancelReason:
          values.status === 'CANCELLED' ? (values.cancelReason ?? '').trim() || null : undefined,
      },
      select: { id: true },
    });

    await writeAudit({
      user: actor,
      entity: 'Appointment',
      entityId: updated.id,
      action: 'APPOINTMENT_STATUS_CHANGED',
      old: { status: appointment.status },
      new: { status: values.status, cancelReason: values.cancelReason ?? null },
    });
    await messageForAppointment(updated.id);
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Follow-ups
// ---------------------------------------------------------------------------

export async function createFollowUpAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(FOLLOW_UP_ROLES, async (actor) => {
    const values = validate(followUpFormSchema, {
      patientId: formData.get('patientId') ?? '',
      appointmentId: formData.get('appointmentId') ?? undefined,
      preset: formData.get('preset') ?? '2w',
      dueDate: formData.get('dueDate') ?? '',
      reason: formData.get('reason') ?? undefined,
    });

    const patient = await prisma.patient.findUnique({
      where: { id: values.patientId },
      select: { id: true, active: true },
    });
    if (!patient || !patient.active) return fail('patientRequired');

    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const todayKey = clinicDateKey(new Date(), tz);
    const preset = FOLLOW_UP_PRESETS.includes(values.preset as never) ? values.preset : '2w';
    const due = values.preset === 'custom' ? values.dueDate! : followUpDueKey(todayKey, preset);

    const followUp = await prisma.followUp.create({
      data: {
        patientId: patient.id,
        dueDate: new Date(`${due}T00:00:00.000Z`),
        reason: values.reason || null,
        status: 'OPEN',
        createdFromAppointmentId: values.appointmentId || null,
        createdBy: actor.id,
      },
      select: { id: true },
    });

    await writeAudit({
      user: actor,
      entity: 'FollowUp',
      entityId: followUp.id,
      action: 'FOLLOW_UP_CREATED',
      new: {
        patientId: patient.id,
        dueDate: due,
        reason: values.reason || null,
        createdFromAppointmentId: values.appointmentId || null,
      },
    });
    await messageForFollowUp(followUp.id);
    return ok({ id: followUp.id });
  });
}

export async function setFollowUpStatusAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(FOLLOW_UP_ROLES, async (actor) => {
    const values = validate(followUpStatusSchema, {
      id: formData.get('id') ?? '',
      status: formData.get('status') ?? '',
    });

    const followUp = await prisma.followUp.findUnique({
      where: { id: values.id },
      select: { id: true, status: true },
    });
    if (!followUp) return fail('notFound');
    if (followUp.status === 'DONE' || followUp.status === 'CANCELLED')
      return fail('invalidTransition');

    const updated = await prisma.followUp.update({
      where: { id: followUp.id },
      data: { status: values.status as never },
      select: { id: true },
    });
    await writeAudit({
      user: actor,
      entity: 'FollowUp',
      entityId: updated.id,
      action: 'FOLLOW_UP_STATUS_CHANGED',
      old: { status: followUp.status },
      new: { status: values.status },
    });
    await messageForFollowUp(updated.id);
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Public website bookings (auto-approved)
// ---------------------------------------------------------------------------

/**
 * `createdBy` marker for rows the public website creates — nobody is signed
 * in behind a booking, and `createdBy` has no FK to the users table.
 */
const PUBLIC_BOOKING_ACTOR = 'public-booking';

export type PublicBookingResult =
  | {
      ok: true;
      id: string;
      /** Echoed back so the website can name the slot it just approved. */
      appointment: { date: string; time: string; doctor: string };
    }
  | { ok: false; error: string };

/**
 * Website bookings never name a doctor, so one is assigned here: an active
 * doctor with nothing overlapping the slot, the least busy of that day when
 * several are free, or any active doctor when every slot is taken (the clinic
 * would rather see the booking than lose it — staff can reassign later).
 */
async function pickBookingDoctor(
  doctors: { id: string; fullName: string }[],
  startsAt: Date,
  endsAt: Date,
  timeZone: string,
): Promise<{ id: string; fullName: string; assignedDespiteClash: boolean }> {
  const ids = doctors.map((doctor) => doctor.id);
  const dayKey = clinicDateKey(startsAt, timeZone);
  const dayFrom = combineDateAndTime(dayKey, '00:00', timeZone);
  const dayTo = combineDateAndTime(dateKeyAdd(dayKey, 1), '00:00', timeZone);

  const [clashes, loads] = await Promise.all([
    prisma.appointment.findMany({
      where: {
        doctorId: { in: ids },
        status: { in: ['SCHEDULED', 'CONFIRMED'] as never },
        startsAt: { lt: endsAt },
        endsAt: { gt: startsAt },
      },
      select: { doctorId: true },
      take: 500,
    }),
    prisma.appointment.groupBy({
      by: ['doctorId'],
      where: {
        doctorId: { in: ids },
        status: { in: ['SCHEDULED', 'CONFIRMED'] as never },
        startsAt: { gte: dayFrom, lt: dayTo },
      },
      _count: { _all: true },
    }),
  ]);

  const busy = new Set(clashes.map((row) => row.doctorId));
  const free = doctors.filter((doctor) => !busy.has(doctor.id));
  const pool = free.length > 0 ? free : doctors;
  const loadOf = (id: string) => loads.find((row) => row.doctorId === id)?._count._all ?? 0;
  const chosen = pool.reduce((best, doctor) => (loadOf(doctor.id) < loadOf(best.id) ? doctor : best));

  return { ...chosen, assignedDespiteClash: free.length === 0 };
}

/** Non-clinical booking text kept on the appointment for the front desk. */
function bookingNotes(
  message: string | null,
  serviceInterest: string | null,
  serviceId: string | null,
): string | null {
  const parts: string[] = [];
  if (message) parts.push(message);
  if (serviceInterest && !serviceId) parts.push(`Service of interest: ${serviceInterest}`);
  const notes = parts.join('\n');
  return notes ? notes.slice(0, MAX_APPT_NOTES) : null;
}

/**
 * Auto-approves a public booking: the visitor is matched (or created) as a
 * patient and a CONFIRMED appointment is inserted straight away — there is no
 * request queue to clear. The messaging hook then queues the confirmation SMS
 * (due immediately) plus the usual reminders.
 */
export async function createPublicBooking(input: {
  name: string;
  phoneE164: string;
  preferredDate: string;
  timeWindow: string | null;
  serviceInterest: string | null;
  message: string | null;
  language: string;
  ipHash: string;
}): Promise<PublicBookingResult> {
  try {
    const tz = await timezone();
    const todayKey = clinicDateKey(new Date(), tz);
    const lastBookableKey = dateKeyAdd(todayKey, MAX_REQUEST_DATE_DAYS_AHEAD);
    if (input.preferredDate < todayKey || input.preferredDate > lastBookableKey) {
      return { ok: false, error: 'invalidDate' };
    }

    const slot = slotForTimeWindow(input.timeWindow);
    const { startsAt, endsAt } = appointmentInstants(
      input.preferredDate,
      slot.start,
      DEFAULT_DURATION,
      tz,
    );
    // "Today" with a slot that has already gone by — nothing left to approve.
    if (startsAt.getTime() <= Date.now()) return { ok: false, error: 'pastSlot' };

    const doctors = await prisma.staff.findMany({
      where: { active: true, isDoctor: true },
      select: { id: true, fullName: true },
      orderBy: { fullName: 'asc' },
    });
    if (doctors.length === 0) return { ok: false, error: 'noDoctorAvailable' };
    const doctor = await pickBookingDoctor(doctors, startsAt, endsAt, tz);

    const { appointmentId, serviceId } = await prisma.$transaction(async (tx) => {
      const existing = await tx.patient.findUnique({
        where: { phoneE164: input.phoneE164 },
        select: { id: true },
      });
      const patientId = existing
        ? existing.id
        : (
            await tx.patient.create({
              data: {
                fullName: input.name,
                phoneE164: input.phoneE164,
                preferredLanguage: input.language === 'am' ? 'am' : 'en',
                createdBy: PUBLIC_BOOKING_ACTOR,
              },
              select: { id: true },
            })
          ).id;

      const service = input.serviceInterest
        ? await tx.service.findFirst({
            where: {
              active: true,
              name: { equals: input.serviceInterest, mode: 'insensitive' },
            },
            select: { id: true },
          })
        : null;

      const appointment = await tx.appointment.create({
        data: {
          patientId,
          doctorId: doctor.id,
          startsAt,
          endsAt,
          serviceId: service?.id ?? null,
          status: 'CONFIRMED',
          createdFrom: 'WEBSITE_REQUEST',
          createdBy: PUBLIC_BOOKING_ACTOR,
          notes: bookingNotes(input.message, input.serviceInterest, service?.id ?? null),
        },
        select: { id: true },
      });

      return { appointmentId: appointment.id, serviceId: service?.id ?? null };
    });

    await writeAudit({
      user: null,
      entity: 'Appointment',
      entityId: appointmentId,
      action: 'APPOINTMENT_CREATED',
      // Public submissions never store the raw IP — only the sha-256 hash.
      ip: null,
      new: {
        source: 'WEBSITE',
        status: 'CONFIRMED',
        doctorId: doctor.id,
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
        serviceId,
        ipHash: input.ipHash,
        ...(doctor.assignedDespiteClash ? { assignedDespiteClash: true } : {}),
      },
    });

    try {
      await messageForAppointment(appointmentId);
    } catch (err) {
      // The booking already exists — a messaging hiccup must not fail it.
      console.error('[appointments] booking message hook failed:', err);
    }

    return {
      ok: true,
      id: appointmentId,
      appointment: { date: input.preferredDate, time: slot.start, doctor: doctor.fullName },
    };
  } catch (err) {
    console.error('[appointments] public booking failed:', err);
    return { ok: false, error: 'unknownError' };
  }
}
