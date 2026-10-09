'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { getSettingsMap } from '@/modules/settings';
import { MESSAGE_OWNER_ROLES, MESSAGE_ROLES, canSendCampaigns } from './roles';
import {
  campaignFormSchema,
  taskEditSchema,
  taskSendSchema,
  taskSkipSchema,
  templateSaveSchema,
  type CampaignFilterValues,
} from './schemas';
import { blockedReason } from './eligibility';
import { generateScheduleChangeTask, runMessageJobs, type JobSummary } from './generate';
import { previewCampaign, materializeCampaign } from './campaign';
import { validateTemplateBody } from './templates';

/** Re-checks a task's eligibility against the live patient + source status. */
async function taskPatient(task: {
  patientId: string;
  sourceType: string;
  sourceId: string;
}): Promise<{ patient: PatientEligibility; sourceStatus: string | null } | null> {
  const patient = await prisma.patient.findUnique({
    where: { id: task.patientId },
    select: { active: true, consentSms: true, doNotMessage: true, phoneE164: true },
  });
  if (!patient) return null;
  let sourceStatus: string | null = null;
  if (task.sourceType === 'APPOINTMENT') {
    const appointment = await prisma.appointment.findUnique({
      where: { id: task.sourceId.split('#')[0] },
      select: { status: true },
    });
    sourceStatus = appointment?.status ?? null;
  } else if (task.sourceType === 'FOLLOWUP') {
    const followUp = await prisma.followUp.findUnique({
      where: { id: task.sourceId },
      select: { status: true },
    });
    sourceStatus = followUp?.status ?? null;
  }
  return { patient, sourceStatus };
}

type PatientEligibility = {
  active: boolean;
  consentSms: boolean;
  doNotMessage: boolean;
  phoneE164: string;
};

export type OpenTaskResult = {
  status: string;
  blockedReason: string | null;
  renderedMessage: string;
  phoneE164: string;
};

/** Re-checks eligibility, then marks the task OPENED (or BLOCKED). */
export async function openMessageTaskAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<OpenTaskResult>> {
  return withAuth<OpenTaskResult>(MESSAGE_ROLES, async () => {
    const values = validate(taskSendSchema, { id: formData.get('id') ?? '' });
    const task = await prisma.messageTask.findUnique({
      where: { id: values.id },
      select: {
        id: true,
        patientId: true,
        sourceType: true,
        sourceId: true,
        status: true,
        renderedMessage: true,
      },
    });
    if (!task) return fail('notFound');
    if (task.status === 'SENT' || task.status === 'SKIPPED' || task.status === 'CANCELLED') {
      return fail('taskAlreadyHandled');
    }

    const context = await taskPatient(task);
    if (!context) return fail('notFound');
    const reason = blockedReason({ patient: context.patient, sourceStatus: context.sourceStatus });

    if (reason) {
      await prisma.messageTask.update({
        where: { id: task.id },
        data: { status: 'BLOCKED', blockedReason: reason },
      });
      return ok({
        status: 'BLOCKED',
        blockedReason: reason,
        renderedMessage: task.renderedMessage,
        phoneE164: context.patient.phoneE164,
      });
    }

    await prisma.messageTask.update({
      where: { id: task.id },
      data: { status: 'OPENED', blockedReason: null },
    });
    return ok({
      status: 'OPENED',
      blockedReason: null,
      renderedMessage: task.renderedMessage,
      phoneE164: context.patient.phoneE164,
    });
  });
}

/** Confirm the receptionist sent the message (Yes, sent). */
export async function confirmMessageSentAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const values = validate(taskSendSchema, { id: formData.get('id') ?? '' });
    const task = await prisma.messageTask.findUnique({
      where: { id: values.id },
      select: { id: true, status: true },
    });
    if (!task) return fail('notFound');
    if (task.status === 'SENT') return ok();

    await prisma.messageTask.update({
      where: { id: task.id },
      data: { status: 'SENT', handledBy: actor.id, handledAt: new Date(), blockedReason: null },
    });
    await writeAudit({
      user: actor,
      entity: 'MessageTask',
      entityId: task.id,
      action: 'MESSAGE_SENT',
    });
    return ok();
  });
}

/** "Not yet" — puts an opened task back in the pending queue. */
export async function postponeMessageTaskAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async () => {
    const values = validate(taskSendSchema, { id: formData.get('id') ?? '' });
    const task = await prisma.messageTask.findUnique({
      where: { id: values.id },
      select: { id: true, status: true },
    });
    if (!task) return fail('notFound');
    if (task.status === 'SENT' || task.status === 'CANCELLED') return fail('taskAlreadyHandled');
    await prisma.messageTask.update({ where: { id: task.id }, data: { status: 'PENDING' } });
    return ok();
  });
}

/** Skip a task with a required reason. */
export async function skipMessageTaskAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const values = validate(taskSkipSchema, {
      id: formData.get('id') ?? '',
      reason: formData.get('reason') ?? '',
    });
    const task = await prisma.messageTask.findUnique({
      where: { id: values.id },
      select: { id: true, status: true },
    });
    if (!task) return fail('notFound');
    if (task.status === 'SENT') return fail('taskAlreadyHandled');

    await prisma.messageTask.update({
      where: { id: task.id },
      data: {
        status: 'SKIPPED',
        skipReason: values.reason,
        handledBy: actor.id,
        handledAt: new Date(),
      },
    });
    await writeAudit({
      user: actor,
      entity: 'MessageTask',
      entityId: task.id,
      action: 'MESSAGE_SKIPPED',
      new: { reason: values.reason },
    });
    return ok();
  });
}

/** One-off edit of the rendered text before sending (logged). */
export async function editMessageTaskAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ message: string }>> {
  return withAuth<{ message: string }>(MESSAGE_ROLES, async (actor) => {
    const values = validate(taskEditSchema, {
      id: formData.get('id') ?? '',
      message: formData.get('message') ?? '',
    });
    const task = await prisma.messageTask.findUnique({
      where: { id: values.id },
      select: { id: true, status: true, renderedMessage: true },
    });
    if (!task) return fail('notFound');
    if (task.status === 'SENT' || task.status === 'CANCELLED') return fail('taskAlreadyHandled');

    await prisma.messageTask.update({
      where: { id: task.id },
      data: { renderedMessage: values.message, editedBy: actor.id, editedAt: new Date() },
    });
    await writeAudit({
      user: actor,
      entity: 'MessageTask',
      entityId: task.id,
      action: 'MESSAGE_EDITED',
      old: { message: task.renderedMessage },
      new: { message: values.message },
    });
    return ok({ message: values.message });
  });
}

/** "Notify patient of change" on an appointment — immediate schedule-change task. */
export async function generateScheduleChangeAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const appointmentId = String(formData.get('appointmentId') ?? '');
    if (!appointmentId) return fail('notFound');
    const result = await generateScheduleChangeTask(appointmentId);
    if (result === 'missing') return fail('notFound');
    await writeAudit({
      user: actor,
      entity: 'Appointment',
      entityId: appointmentId,
      action: 'SCHEDULE_CHANGE_NOTICE_PREPARED',
    });
    return ok();
  });
}

/** Manual "run jobs now" button (OWNER). */
export async function runMessageJobsAction(
  _prev: ActionResult | null,
  _formData: FormData,
): Promise<ActionResult<JobSummary>> {
  void _prev;
  void _formData;
  return withAuth<JobSummary>(MESSAGE_OWNER_ROLES, async () => {
    const summary = await runMessageJobs(new Date());
    return ok(summary);
  });
}

export async function saveMessageTemplateAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_OWNER_ROLES, async (actor) => {
    const values = validate(templateSaveSchema, {
      id: formData.get('id') ?? '',
      key: formData.get('key') ?? '',
      language: formData.get('language') ?? '',
      body: formData.get('body') ?? '',
      active: formData.get('active') ?? 'false',
    });
    const check = validateTemplateBody(values.body);
    if (!check.ok) return fail('validationError', { body: 'unknownVariable' });

    const existing = await prisma.messageTemplate.findUnique({ where: { id: values.id } });
    if (!existing) return fail('notFound');

    await prisma.messageTemplate.update({
      where: { id: existing.id },
      data: { body: values.body, active: values.active, updatedBy: actor.id },
    });
    await writeAudit({
      user: actor,
      entity: 'MessageTemplate',
      entityId: existing.id,
      action: 'MESSAGE_TEMPLATE_UPDATED',
      old: { body: existing.body, active: existing.active },
      new: { body: values.body, active: values.active },
    });
    return ok();
  });
}

/** Previews a campaign audience (used by the builder's Preview step). */
export async function previewCampaignAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ eligible: number; matched: number; overCap: boolean }>> {
  return withAuth(MESSAGE_ROLES, async () => {
    const filter = readCampaignFilter(formData);
    const preview = await previewCampaign(filter);
    return ok({ eligible: preview.eligible, matched: preview.matched, overCap: preview.overCap });
  });
}

function readCampaignFilter(formData: FormData): CampaignFilterValues {
  return {
    tags: String(formData.get('tags') ?? '')
      .split(',')
      .map((tag) => tag.trim())
      .filter(Boolean),
    tagMode: String(formData.get('tagMode') ?? 'ANY') as 'ANY' | 'ALL',
    language: String(formData.get('language') ?? '') as '' | 'en' | 'am',
    lastVisitFrom: String(formData.get('lastVisitFrom') ?? ''),
    lastVisitTo: String(formData.get('lastVisitTo') ?? ''),
    upcoming: String(formData.get('upcoming') ?? 'ANY') as 'ANY' | 'HAS' | 'NONE',
    serviceCategory: String(
      formData.get('serviceCategory') ?? '',
    ) as CampaignFilterValues['serviceCategory'],
    birthdayMonth: Number(formData.get('birthdayMonth') ?? 0),
  };
}

export async function createCampaignAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string; created: number }>> {
  return withAuth<{ id: string; created: number }>(MESSAGE_ROLES, async (actor) => {
    const settings = await getSettingsMap();
    const receptionAllowed = settings.reception_can_send_campaigns === true;
    if (!canSendCampaigns(actor.role, receptionAllowed)) return fail('forbidden');

    const values = validate(campaignFormSchema, {
      ...readCampaignFilter(formData),
      title: formData.get('title') ?? '',
      templateKey: formData.get('templateKey') ?? '',
      customBody: formData.get('customBody') ?? '',
      languageMode: formData.get('languageMode') ?? 'PATIENT',
      fixedLanguage: formData.get('fixedLanguage') ?? 'en',
      expiresAt: formData.get('expiresAt') ?? '',
      acknowledgedGroupWarning: formData.get('acknowledgedGroupWarning') ?? 'false',
    });

    if (values.templateKey === 'CUSTOM' && !(values.customBody ?? '').trim()) {
      return fail('validationError', { customBody: 'required' });
    }

    const filter = {
      tags: values.tags,
      tagMode: values.tagMode,
      language: values.language,
      lastVisitFrom: values.lastVisitFrom,
      lastVisitTo: values.lastVisitTo,
      upcoming: values.upcoming,
      serviceCategory: values.serviceCategory,
      birthdayMonth: values.birthdayMonth,
    };

    const campaign = await prisma.campaign.create({
      data: {
        title: values.title,
        templateKey: values.templateKey,
        customBody: values.templateKey === 'CUSTOM' ? (values.customBody ?? null) : null,
        languageMode: values.languageMode,
        fixedLanguage: values.languageMode === 'FIXED' ? values.fixedLanguage : null,
        filterJson: filter,
        status: 'ACTIVE',
        expiresAt: values.expiresAt ? new Date(`${values.expiresAt}T23:59:00.000Z`) : null,
        createdBy: actor.id,
      },
      select: { id: true },
    });

    const result = await materializeCampaign({
      campaignId: campaign.id,
      campaignTitle: values.title,
      filter,
      template: {
        templateKey: values.templateKey,
        customBody: values.customBody ?? null,
        languageMode: values.languageMode,
        fixedLanguage: values.fixedLanguage,
      },
      expiresAt: values.expiresAt ? new Date(`${values.expiresAt}T23:59:00.000Z`) : null,
    });

    if (result.overCap) {
      await prisma.campaign.update({ where: { id: campaign.id }, data: { status: 'CANCELLED' } });
      return fail('campaignOverCap');
    }

    await writeAudit({
      user: actor,
      entity: 'Campaign',
      entityId: campaign.id,
      action: 'CAMPAIGN_ACTIVATED',
      new: { title: values.title, recipients: result.created, filter },
    });
    return ok({ id: campaign.id, created: result.created });
  });
}

export async function cancelCampaignAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const campaign = await prisma.campaign.findUnique({
      where: { id },
      select: { id: true, status: true },
    });
    if (!campaign) return fail('notFound');
    if (campaign.status === 'CANCELLED' || campaign.status === 'COMPLETED') return ok();

    await prisma.$transaction([
      prisma.messageTask.updateMany({
        where: { campaignId: id, status: { in: ['PENDING', 'OPENED'] } },
        data: { status: 'CANCELLED' },
      }),
      prisma.campaign.update({ where: { id }, data: { status: 'CANCELLED' } }),
    ]);
    await writeAudit({
      user: actor,
      entity: 'Campaign',
      entityId: id,
      action: 'CAMPAIGN_CANCELLED',
    });
    return ok();
  });
}

/** Records that the operator acknowledged the group-chat warning before copying. */
export async function acknowledgeGroupCopyAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const campaignId = String(formData.get('campaignId') ?? '');
    await writeAudit({
      user: actor,
      entity: 'Campaign',
      entityId: campaignId || 'unknown',
      action: 'CAMPAIGN_COPY_ALL_ACKNOWLEDGED',
    });
    return ok();
  });
}
