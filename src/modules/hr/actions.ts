'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { clinicDateKey, combineDateAndTime, dateKeyAdd } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { HR_MANAGE_ROLES, HR_LEAVE_ROLES, HR_OWNER_ROLES, isSelfApproval } from './roles';
import {
  generateFromTemplateSchema,
  leaveCancelSchema,
  leaveDecisionSchema,
  leaveRequestSchema,
  shiftFormSchema,
  templateFormSchema,
} from './schemas';
import {
  findOverlappingShift,
  generateTemplateShifts,
  shiftByDays,
  shiftInstantsForDay,
  weekStartKey,
  type ShiftLike,
  type TemplateInput,
} from './shifts';
import { findOverlappingLeave } from './leave';

/** A candidate shift's instants from the form's date + clinic-local times. */
function candidateInstants(dateKey: string, startTime: string, endTime: string, tz: string) {
  return shiftInstantsForDay({ startTime, endTime }, dateKey, tz);
}

async function conflictingShift(
  staffId: string,
  startsAt: Date,
  endsAt: Date,
  ignoreId?: string,
): Promise<{ id: string; startsAt: Date; endsAt: Date } | null> {
  const neighbours = await prisma.shift.findMany({
    where: { staffId, startsAt: { lt: endsAt }, endsAt: { gt: startsAt } },
    select: { id: true, staffId: true, startsAt: true, endsAt: true },
  });
  const found = findOverlappingShift(neighbours as ShiftLike[], {
    staffId,
    startsAt,
    endsAt,
    ignoreId,
  });
  return found ? { id: found.id, startsAt: found.startsAt, endsAt: found.endsAt } : null;
}

// ---------------------------------------------------------------------------
// Shifts
// ---------------------------------------------------------------------------

export async function createShiftAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(HR_MANAGE_ROLES, async (actor) => {
    const values = validate(shiftFormSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      dateKey: String(formData.get('dateKey') ?? ''),
      startTime: String(formData.get('startTime') ?? ''),
      endTime: String(formData.get('endTime') ?? ''),
      note: String(formData.get('note') ?? ''),
    });

    const staff = await prisma.staff.findUnique({
      where: { id: values.staffId },
      select: { id: true, active: true, fullName: true },
    });
    if (!staff || !staff.active) return fail('staffRequired');

    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const { startsAt, endsAt } = candidateInstants(
      values.dateKey,
      values.startTime,
      values.endTime,
      tz,
    );
    const clash = await conflictingShift(values.staffId, startsAt, endsAt);
    if (clash) return fail('shiftOverlap');

    const shift = await prisma.shift.create({
      data: {
        staffId: values.staffId,
        startsAt,
        endsAt,
        source: 'MANUAL',
        note: values.note || null,
        createdBy: actor.id,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'Shift',
      entityId: shift.id,
      action: 'SHIFT_CREATED',
      new: {
        staff: staff.fullName,
        startsAt: startsAt.toISOString(),
        endsAt: endsAt.toISOString(),
        source: 'MANUAL',
        note: values.note || null,
      },
    });

    return ok({ id: shift.id });
  });
}

export async function updateShiftAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_MANAGE_ROLES, async (actor) => {
    const values = validate(shiftFormSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      dateKey: String(formData.get('dateKey') ?? ''),
      startTime: String(formData.get('startTime') ?? ''),
      endTime: String(formData.get('endTime') ?? ''),
      note: String(formData.get('note') ?? ''),
    });
    const id = String(formData.get('id') ?? '');
    if (!id) return fail('unknownError');

    const before = await prisma.shift.findUnique({ where: { id } });
    if (!before) return fail('unknownError');

    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const { startsAt, endsAt } = candidateInstants(
      values.dateKey,
      values.startTime,
      values.endTime,
      tz,
    );
    const clash = await conflictingShift(values.staffId, startsAt, endsAt, id);
    if (clash) return fail('shiftOverlap');

    const updated = await prisma.shift.update({
      where: { id },
      data: {
        staffId: values.staffId,
        startsAt,
        endsAt,
        note: values.note || null,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'Shift',
      entityId: id,
      action: 'SHIFT_UPDATED',
      old: {
        staffId: before.staffId,
        startsAt: before.startsAt.toISOString(),
        endsAt: before.endsAt.toISOString(),
        note: before.note,
      },
      new: {
        staffId: updated.staffId,
        startsAt: updated.startsAt.toISOString(),
        endsAt: updated.endsAt.toISOString(),
        note: updated.note,
      },
    });

    return ok();
  });
}

export async function deleteShiftAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_MANAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const before = await prisma.shift.findUnique({ where: { id } });
    if (!before) return ok();

    await prisma.shift.delete({ where: { id } });
    await writeAudit({
      user: actor,
      entity: 'Shift',
      entityId: id,
      action: 'SHIFT_DELETED',
      old: {
        staffId: before.staffId,
        startsAt: before.startsAt.toISOString(),
        endsAt: before.endsAt.toISOString(),
        source: before.source,
        note: before.note,
      },
    });
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Weekly templates
// ---------------------------------------------------------------------------

export async function createTemplateAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(HR_MANAGE_ROLES, async (actor) => {
    const values = validate(templateFormSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      weekday: String(formData.get('weekday') ?? ''),
      startTime: String(formData.get('startTime') ?? ''),
      endTime: String(formData.get('endTime') ?? ''),
      validFrom: String(formData.get('validFrom') ?? ''),
      validTo: String(formData.get('validTo') ?? ''),
    });

    const staff = await prisma.staff.findUnique({
      where: { id: values.staffId },
      select: { id: true, fullName: true },
    });
    if (!staff) return fail('staffRequired');

    const template = await prisma.shiftTemplate.create({
      data: {
        staffId: values.staffId,
        weekday: values.weekday,
        startTime: values.startTime,
        endTime: values.endTime,
        validFrom: new Date(`${values.validFrom}T00:00:00.000Z`),
        validTo: values.validTo ? new Date(`${values.validTo}T00:00:00.000Z`) : null,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'ShiftTemplate',
      entityId: template.id,
      action: 'SHIFT_TEMPLATE_CREATED',
      new: {
        staff: staff.fullName,
        weekday: values.weekday,
        startTime: values.startTime,
        endTime: values.endTime,
        validFrom: values.validFrom,
        validTo: values.validTo || null,
      },
    });

    return ok({ id: template.id });
  });
}

export async function deleteTemplateAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_MANAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const before = await prisma.shiftTemplate.findUnique({ where: { id } });
    if (!before) return ok();
    await prisma.shiftTemplate.delete({ where: { id } });
    await writeAudit({
      user: actor,
      entity: 'ShiftTemplate',
      entityId: id,
      action: 'SHIFT_TEMPLATE_DELETED',
      old: {
        staffId: before.staffId,
        weekday: before.weekday,
        startTime: before.startTime,
        endTime: before.endTime,
      },
    });
    return ok();
  });
}

export type TemplatePreview = {
  count: number;
  skipped: number;
  staffCount: number;
  sample: Array<{ staffName: string; dateKey: string; start: string; end: string }>;
};

function serializeTemplateInputs(
  rows: Array<{
    id: string;
    staffId: string;
    weekday: number;
    startTime: string;
    endTime: string;
    validFrom: Date;
    validTo: Date | null;
  }>,
): TemplateInput[] {
  return rows.map((row) => ({
    id: row.id,
    staffId: row.staffId,
    weekday: row.weekday,
    startTime: row.startTime,
    endTime: row.endTime,
    validFrom: row.validFrom,
    validTo: row.validTo,
  }));
}

async function buildPreview(
  fromKey: string,
  toKey: string,
  staffId: string | undefined,
  tz: string,
): Promise<TemplatePreview> {
  const templates = await prisma.shiftTemplate.findMany({
    where: staffId ? { staffId } : {},
    select: {
      id: true,
      staffId: true,
      weekday: true,
      startTime: true,
      endTime: true,
      validFrom: true,
      validTo: true,
      staff: { select: { fullName: true } },
    },
  });
  const generated = generateTemplateShifts(serializeTemplateInputs(templates), fromKey, toKey, tz);

  const rangeFrom = combineDateAndTime(fromKey, '00:00', tz);
  const rangeTo = combineDateAndTime(dateKeyAdd(toKey, 1), '00:00', tz);
  const existing = await prisma.shift.findMany({
    where: { startsAt: { lt: rangeTo }, endsAt: { gt: rangeFrom } },
    select: { id: true, staffId: true, startsAt: true, endsAt: true },
  });

  let skipped = 0;
  const accepted: typeof generated = [];
  for (const shift of generated) {
    const clash = findOverlappingShift(existing as ShiftLike[], {
      staffId: shift.staffId,
      startsAt: shift.startsAt,
      endsAt: shift.endsAt,
    });
    if (clash) skipped += 1;
    else accepted.push(shift);
  }

  const nameById = new Map(templates.map((t) => [t.staffId, t.staff.fullName]));
  const sample = accepted.slice(0, 12).map((shift) => ({
    staffName: nameById.get(shift.staffId) ?? '—',
    dateKey: shift.dateKey,
    start: shift.startsAt.toISOString(),
    end: shift.endsAt.toISOString(),
  }));

  return {
    count: accepted.length,
    skipped,
    staffCount: new Set(generated.map((shift) => shift.staffId)).size,
    sample,
  };
}

/** Preview template-generated shifts without writing anything. */
export async function previewTemplateAction(
  _prev: ActionResult<TemplatePreview> | null,
  formData: FormData,
): Promise<ActionResult<TemplatePreview>> {
  return withAuth<TemplatePreview>(HR_MANAGE_ROLES, async () => {
    const values = validate(generateFromTemplateSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      fromKey: String(formData.get('fromKey') ?? ''),
      toKey: String(formData.get('toKey') ?? ''),
    });
    const settings = await getSettingsMap();
    const preview = await buildPreview(
      values.fromKey,
      values.toKey,
      values.staffId || undefined,
      timezoneFrom(settings),
    );
    return ok(preview);
  });
}

/** Apply the template over a range — skips overlaps, audits one summary row. */
export async function applyTemplateAction(
  _prev: ActionResult<TemplatePreview> | null,
  formData: FormData,
): Promise<ActionResult<TemplatePreview>> {
  return withAuth<TemplatePreview>(HR_MANAGE_ROLES, async (actor) => {
    const values = validate(generateFromTemplateSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      fromKey: String(formData.get('fromKey') ?? ''),
      toKey: String(formData.get('toKey') ?? ''),
    });
    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const preview = await buildPreview(
      values.fromKey,
      values.toKey,
      values.staffId || undefined,
      timezoneFrom(settings),
    );

    if (preview.count > 0) {
      const templates = await prisma.shiftTemplate.findMany({
        where: values.staffId ? { staffId: values.staffId } : {},
        select: {
          id: true,
          staffId: true,
          weekday: true,
          startTime: true,
          endTime: true,
          validFrom: true,
          validTo: true,
        },
      });
      const generated = generateTemplateShifts(
        serializeTemplateInputs(templates),
        values.fromKey,
        values.toKey,
        tz,
      );
      const rangeFrom = combineDateAndTime(values.fromKey, '00:00', tz);
      const rangeTo = combineDateAndTime(dateKeyAdd(values.toKey, 1), '00:00', tz);
      const existing = await prisma.shift.findMany({
        where: { startsAt: { lt: rangeTo }, endsAt: { gt: rangeFrom } },
        select: { id: true, staffId: true, startsAt: true, endsAt: true },
      });

      const rows = generated.filter(
        (shift) =>
          !findOverlappingShift(existing as ShiftLike[], {
            staffId: shift.staffId,
            startsAt: shift.startsAt,
            endsAt: shift.endsAt,
          }),
      );

      await prisma.shift.createMany({
        data: rows.map((shift) => ({
          staffId: shift.staffId,
          startsAt: shift.startsAt,
          endsAt: shift.endsAt,
          source: 'TEMPLATE' as const,
          createdBy: actor.id,
        })),
      });

      await writeAudit({
        user: actor,
        entity: 'Shift',
        entityId: `template:${values.fromKey}:${values.toKey}`,
        action: 'SHIFTS_GENERATED',
        new: {
          from: values.fromKey,
          to: values.toKey,
          staffId: values.staffId || null,
          created: rows.length,
          skipped: preview.skipped,
        },
      });
    }

    return ok(preview);
  });
}

/** Copy the previous week's shifts into the target week (skips overlaps). */
export async function copyLastWeekAction(
  _prev: ActionResult<TemplatePreview> | null,
  formData: FormData,
): Promise<ActionResult<TemplatePreview>> {
  return withAuth<TemplatePreview>(HR_MANAGE_ROLES, async (actor) => {
    const weekParam = String(formData.get('weekStart') ?? '');
    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const baseKey = /^\d{4}-\d{2}-\d{2}$/.test(weekParam)
      ? weekParam
      : clinicDateKey(new Date(), tz);
    const targetStart = weekStartKey(baseKey);
    const sourceStart = dateKeyAdd(targetStart, -7);

    const sourceFrom = combineDateAndTime(sourceStart, '00:00', tz);
    const sourceTo = combineDateAndTime(dateKeyAdd(sourceStart, 7), '00:00', tz);
    const targetFrom = combineDateAndTime(targetStart, '00:00', tz);
    const targetTo = combineDateAndTime(dateKeyAdd(targetStart, 7), '00:00', tz);

    const [sourceShifts, existing] = await Promise.all([
      prisma.shift.findMany({
        where: { startsAt: { lt: sourceTo }, endsAt: { gt: sourceFrom } },
        select: { staffId: true, startsAt: true, endsAt: true, note: true },
      }),
      prisma.shift.findMany({
        where: { startsAt: { lt: targetTo }, endsAt: { gt: targetFrom } },
        select: { id: true, staffId: true, startsAt: true, endsAt: true },
      }),
    ]);

    const shifted = sourceShifts.map((shift) => shiftByDays(shift, 7));
    const rows = shifted.filter(
      (shift) =>
        !findOverlappingShift(existing as ShiftLike[], {
          staffId: shift.staffId,
          startsAt: shift.startsAt,
          endsAt: shift.endsAt,
        }),
    );

    if (rows.length > 0) {
      await prisma.shift.createMany({
        data: rows.map((shift) => ({
          staffId: shift.staffId,
          startsAt: shift.startsAt,
          endsAt: shift.endsAt,
          source: 'MANUAL' as const,
          note: shift.note,
          createdBy: actor.id,
        })),
      });
      await writeAudit({
        user: actor,
        entity: 'Shift',
        entityId: `copy:${sourceStart}:${targetStart}`,
        action: 'SHIFTS_COPIED',
        new: {
          from: sourceStart,
          to: targetStart,
          created: rows.length,
          skipped: shifted.length - rows.length,
        },
      });
    }

    const preview: TemplatePreview = {
      count: rows.length,
      skipped: shifted.length - rows.length,
      staffCount: new Set(rows.map((shift) => shift.staffId)).size,
      sample: [],
    };
    return ok(preview);
  });
}

// ---------------------------------------------------------------------------
// Leave
// ---------------------------------------------------------------------------

export async function requestLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(HR_LEAVE_ROLES, async (actor) => {
    const values = validate(leaveRequestSchema, {
      staffId: String(formData.get('staffId') ?? ''),
      type: String(formData.get('type') ?? ''),
      startDate: String(formData.get('startDate') ?? ''),
      endDate: String(formData.get('endDate') ?? ''),
      halfDay: String(formData.get('halfDay') ?? 'false'),
      reason: String(formData.get('reason') ?? ''),
    });

    // Everyone requests for themselves unless the OWNER is acting on behalf.
    const actorStaffId = await getActorStaffId(actor.id);
    const canActForOthers = actor.role === 'OWNER';
    let staffId = actorStaffId;
    if (canActForOthers && values.staffId) staffId = values.staffId;
    if (!staffId) return fail('staffRequired');

    const staff = await prisma.staff.findUnique({
      where: { id: staffId },
      select: { id: true, fullName: true },
    });
    if (!staff) return fail('staffRequired');

    const start = new Date(`${values.startDate}T00:00:00.000Z`);
    const end = new Date(`${values.endDate}T00:00:00.000Z`);

    // Reject overlap with an existing APPROVED leave for the same person.
    const approved = await prisma.leaveRequest.findMany({
      where: { staffId, status: 'APPROVED' },
      select: {
        id: true,
        staffId: true,
        type: true,
        status: true,
        startDate: true,
        endDate: true,
        halfDay: true,
      },
    });
    if (findOverlappingLeave(approved, { staffId, startDate: start, endDate: end })) {
      return fail('leaveOverlap');
    }

    const created = await prisma.leaveRequest.create({
      data: {
        staffId,
        type: values.type,
        startDate: start,
        endDate: end,
        halfDay: values.halfDay,
        reason: values.reason || null,
        status: 'REQUESTED',
        createdBy: actor.id,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'LeaveRequest',
      entityId: created.id,
      action: 'LEAVE_REQUESTED',
      new: {
        staff: staff.fullName,
        type: values.type,
        startDate: values.startDate,
        endDate: values.endDate,
        halfDay: values.halfDay,
        reason: values.reason || null,
      },
    });

    return ok({ id: created.id });
  });
}

async function getActorStaffId(userId: string): Promise<string | null> {
  const user = await prisma.user.findUnique({
    where: { id: userId },
    select: { staffId: true },
  });
  return user?.staffId ?? null;
}

export async function approveLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_OWNER_ROLES, async (actor) => {
    const values = validate(leaveDecisionSchema, {
      leaveId: String(formData.get('leaveId') ?? ''),
      note: String(formData.get('note') ?? ''),
    });
    const leave = await prisma.leaveRequest.findUnique({ where: { id: values.leaveId } });
    if (!leave || leave.status !== 'REQUESTED') return fail('leaveNotPending');

    // Segregation of duties: nobody approves their own leave.
    const actorStaffId = await getActorStaffId(actor.id);
    if (isSelfApproval(actorStaffId, leave.staffId)) return fail('cannotDecideOwnLeave');

    await prisma.leaveRequest.update({
      where: { id: leave.id },
      data: {
        status: 'APPROVED',
        decidedBy: actor.id,
        decidedAt: new Date(),
        decisionNote: values.note || null,
      },
    });
    await writeAudit({
      user: actor,
      entity: 'LeaveRequest',
      entityId: leave.id,
      action: 'LEAVE_APPROVED',
      old: { status: 'REQUESTED' },
      new: { status: 'APPROVED', decisionNote: values.note || null },
    });
    return ok();
  });
}

export async function rejectLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_OWNER_ROLES, async (actor) => {
    const values = validate(leaveDecisionSchema, {
      leaveId: String(formData.get('leaveId') ?? ''),
      note: String(formData.get('note') ?? ''),
    });
    const leave = await prisma.leaveRequest.findUnique({ where: { id: values.leaveId } });
    if (!leave || leave.status !== 'REQUESTED') return fail('leaveNotPending');

    const actorStaffId = await getActorStaffId(actor.id);
    if (isSelfApproval(actorStaffId, leave.staffId)) return fail('cannotDecideOwnLeave');

    await prisma.leaveRequest.update({
      where: { id: leave.id },
      data: {
        status: 'REJECTED',
        decidedBy: actor.id,
        decidedAt: new Date(),
        decisionNote: values.note || null,
      },
    });
    await writeAudit({
      user: actor,
      entity: 'LeaveRequest',
      entityId: leave.id,
      action: 'LEAVE_REJECTED',
      old: { status: 'REQUESTED' },
      new: { status: 'REJECTED', decisionNote: values.note || null },
    });
    return ok();
  });
}

export async function cancelLeaveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(HR_LEAVE_ROLES, async (actor) => {
    const values = validate(leaveCancelSchema, {
      leaveId: String(formData.get('leaveId') ?? ''),
      note: String(formData.get('note') ?? ''),
    });
    const leave = await prisma.leaveRequest.findUnique({ where: { id: values.leaveId } });
    if (!leave) return fail('unknownError');
    if (leave.status !== 'REQUESTED' && leave.status !== 'APPROVED') {
      return fail('leaveNotPending');
    }

    const actorStaffId = await getActorStaffId(actor.id);
    const isSelf = !!actorStaffId && actorStaffId === leave.staffId;
    const isOwner = actor.role === 'OWNER';
    // The OWNER may cancel anything; a staff member only their own still-pending
    // request. Cancelling an approved request needs the OWNER.
    if (!isOwner && !(isSelf && leave.status === 'REQUESTED')) return fail('cannotCancel');

    await prisma.leaveRequest.update({
      where: { id: leave.id },
      data: { status: 'CANCELLED', decidedBy: actor.id, decidedAt: new Date() },
    });
    await writeAudit({
      user: actor,
      entity: 'LeaveRequest',
      entityId: leave.id,
      action: 'LEAVE_CANCELLED',
      old: { status: leave.status },
      new: { status: 'CANCELLED', note: values.note || null },
    });
    return ok();
  });
}
