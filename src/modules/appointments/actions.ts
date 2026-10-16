'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { normalizePhone } from '@/lib/phone';
import { APPT_ROLES, REQUEST_ROLES, FOLLOW_UP_ROLES } from './roles';
import {
  appointmentFormSchema,
  appointmentStatusSchema,
  convertRequestSchema,
  followUpFormSchema,
  followUpStatusSchema,
  requestStatusSchema,
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
import { FOLLOW_UP_PRESETS } from './constants';

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
// Website requests
// ---------------------------------------------------------------------------

export async function convertRequestAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(REQUEST_ROLES, async (actor) => {
    const values = validate(convertRequestSchema, {
      requestId: formData.get('requestId') ?? '',
      doctorId: formData.get('doctorId') ?? '',
      serviceId: formData.get('serviceId') ?? undefined,
      patientId: formData.get('patientId') ?? undefined,
      notes: formData.get('notes') ?? undefined,
    });

    const request = await prisma.appointmentRequest.findUnique({
      where: { id: values.requestId },
      select: {
        id: true,
        name: true,
        phoneE164: true,
        preferredDate: true,
        preferredTimeWindow: true,
        language: true,
        status: true,
        appointmentId: true,
      },
    });
    if (!request) return fail('notFound');
    if (request.appointmentId || request.status === 'CONVERTED')
      return fail('requestAlreadyConverted');

    const doctor = await prisma.staff.findUnique({
      where: { id: values.doctorId },
      select: { id: true, active: true, isDoctor: true },
    });
    if (!doctor || !doctor.active || !doctor.isDoctor) return fail('doctorRequired');

    const tz = await timezone();
    const slot = slotForTimeWindow(request.preferredTimeWindow);
    const { startsAt, endsAt } = appointmentInstants(request.preferredDate, slot.start, 30, tz);

    const appointmentId = await prisma.$transaction(async (tx) => {
      let patientId = values.patientId || null;
      if (!patientId) {
        const existing = await tx.patient.findUnique({
          where: { phoneE164: request.phoneE164 },
          select: { id: true },
        });
        patientId = existing
          ? existing.id
          : (
              await tx.patient.create({
                data: {
                  fullName: request.name,
                  phoneE164: request.phoneE164,
                  createdBy: actor.id,
                  preferredLanguage: request.language === 'am' ? 'am' : 'en',
                },
                select: { id: true },
              })
            ).id;
      }

      const appointment = await tx.appointment.create({
        data: {
          patientId,
          doctorId: doctor.id,
          startsAt,
          endsAt,
          serviceId: values.serviceId || null,
          status: 'SCHEDULED',
          createdFrom: 'WEBSITE_REQUEST',
          createdBy: actor.id,
          notes: values.notes || null,
        },
        select: { id: true },
      });

      await tx.appointmentRequest.update({
        where: { id: request.id },
        data: {
          status: 'CONVERTED',
          handledBy: actor.id,
          handledAt: new Date(),
          patientId,
          appointmentId: appointment.id,
        },
      });

      return appointment.id;
    });

    await writeAudit({
      user: actor,
      entity: 'AppointmentRequest',
      entityId: request.id,
      action: 'REQUEST_CONVERTED',
      new: {
        appointmentId,
        doctorId: doctor.id,
        patientId: values.patientId ?? 'matched-or-created',
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
      },
    });
    await messageForAppointment(appointmentId);
    return ok({ id: appointmentId });
  });
}

export async function setRequestStatusAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(REQUEST_ROLES, async (actor) => {
    const values = validate(requestStatusSchema, {
      id: formData.get('id') ?? '',
      status: formData.get('status') ?? '',
    });

    const request = await prisma.appointmentRequest.findUnique({
      where: { id: values.id },
      select: { id: true, status: true, handledBy: true },
    });
    if (!request) return fail('notFound');
    if (request.status === 'CONVERTED') return fail('requestAlreadyConverted');

    const updated = await prisma.appointmentRequest.update({
      where: { id: request.id },
      data: {
        status: values.status as never,
        handledBy: request.handledBy ?? actor.id,
        handledAt: request.handledBy ? undefined : new Date(),
      },
      select: { id: true },
    });

    await writeAudit({
      user: actor,
      entity: 'AppointmentRequest',
      entityId: updated.id,
      action: 'REQUEST_STATUS_CHANGED',
      old: { status: request.status },
      new: { status: values.status },
    });
    return ok();
  });
}

/** Shared by the public POST route (validates + normalizes before calling). */
export async function createPublicRequest(input: {
  name: string;
  phoneE164: string;
  preferredDate: string;
  timeWindow: string | null;
  serviceInterest: string | null;
  message: string | null;
  language: string;
  ipHash: string;
}): Promise<{ ok: true; id: string } | { ok: false; error: string }> {
  try {
    const existing = await prisma.patient.findUnique({
      where: { phoneE164: input.phoneE164 },
      select: { id: true },
    });
    const request = await prisma.appointmentRequest.create({
      data: {
        name: input.name,
        phoneE164: input.phoneE164,
        preferredDate: input.preferredDate,
        preferredTimeWindow: input.timeWindow,
        serviceInterest: input.serviceInterest,
        message: input.message,
        language: input.language,
        ipHash: input.ipHash,
        patientId: existing?.id ?? null,
      },
      select: { id: true },
    });
    return { ok: true, id: request.id };
  } catch (err) {
    console.error('[appointments] public request create failed:', err);
    return { ok: false, error: 'unknownError' };
  }
}
