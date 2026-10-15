'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { PATIENT_ROLES } from '../roles';
import { patientSickLeaveSchema } from './schemas';

function toDate(value: string): Date {
  return new Date(`${value}T00:00:00Z`);
}

export async function createPatientSickLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(PATIENT_ROLES, async (actor) => {
    const values = validate(patientSickLeaveSchema, {
      patientId: String(formData.get('patientId') ?? ''),
      startDate: String(formData.get('startDate') ?? ''),
      endDate: String(formData.get('endDate') ?? ''),
      reason: String(formData.get('reason') ?? ''),
      note: String(formData.get('note') ?? ''),
    });

    const patient = await prisma.patient.findUnique({ where: { id: values.patientId } });
    if (!patient) return fail('patientRequired');

    const created = await prisma.patientSickLeave.create({
      data: {
        patientId: values.patientId,
        startDate: toDate(values.startDate),
        endDate: toDate(values.endDate),
        reason: values.reason || null,
        note: values.note || null,
        recordedById: actor.id,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'PatientSickLeave',
      entityId: created.id,
      action: 'PATIENT_SICK_LEAVE_CREATED',
      new: {
        patientId: created.patientId,
        startDate: created.startDate,
        endDate: created.endDate,
        reason: created.reason,
        note: created.note,
      },
    });

    return ok({ id: created.id });
  });
}

export async function updatePatientSickLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const values = validate(patientSickLeaveSchema, {
      patientId: String(formData.get('patientId') ?? ''),
      startDate: String(formData.get('startDate') ?? ''),
      endDate: String(formData.get('endDate') ?? ''),
      reason: String(formData.get('reason') ?? ''),
      note: String(formData.get('note') ?? ''),
    });

    const before = await prisma.patientSickLeave.findUnique({ where: { id } });
    if (!before) return fail('unknownError');
    if (before.patientId !== values.patientId) return fail('forbidden');

    const updated = await prisma.patientSickLeave.update({
      where: { id },
      data: {
        startDate: toDate(values.startDate),
        endDate: toDate(values.endDate),
        reason: values.reason || null,
        note: values.note || null,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'PatientSickLeave',
      entityId: id,
      action: 'PATIENT_SICK_LEAVE_UPDATED',
      old: {
        startDate: before.startDate,
        endDate: before.endDate,
        reason: before.reason,
        note: before.note,
      },
      new: {
        startDate: updated.startDate,
        endDate: updated.endDate,
        reason: updated.reason,
        note: updated.note,
      },
    });

    return ok({ id: updated.id });
  });
}

export async function deletePatientSickLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');

    const before = await prisma.patientSickLeave.findUnique({ where: { id } });
    if (!before) return fail('unknownError');

    await prisma.patientSickLeave.delete({ where: { id } });

    await writeAudit({
      user: actor,
      entity: 'PatientSickLeave',
      entityId: id,
      action: 'PATIENT_SICK_LEAVE_DELETED',
      old: {
        patientId: before.patientId,
        startDate: before.startDate,
        endDate: before.endDate,
        reason: before.reason,
        note: before.note,
      },
    });

    return ok();
  });
}
