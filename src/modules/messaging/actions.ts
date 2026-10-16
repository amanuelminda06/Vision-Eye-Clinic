'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { getSettingsMap } from '@/modules/settings';
import {
  MESSAGE_OWNER_ROLES,
  MESSAGE_ROLES,
  canOverrideGroupFilter,
  canSendCampaigns,
} from './roles';
import {
  campaignFormSchema,
  messageDraftSchema,
  libraryItemSchema,
  audienceFormSchema,
  taskEditSchema,
  taskSendSchema,
  taskSkipSchema,
  templateSaveSchema,
  type RecipientFilterValues,
} from './schemas';
import { blockedReason } from './eligibility';
import { generateScheduleChangeTask, runMessageJobs, type JobSummary } from './generate';
import { previewCampaign, materializeCampaign } from './campaign';
import { groupAllowed } from './recipient-filter';
import { searchPickablePatients, type PickablePatient } from './recipients';
import { messageBodyHash, titleFromBody } from './message-hash';
import { validateMessageBody, validateTemplateBody } from './templates';

/** Re-checks a task's eligibility against the live patient + source status. */
async function taskPatient(task: {
  patientId: string;
  sourceType: string;
  sourceId: string;
}): Promise<{ patient: PatientEligibility; sourceStatus: string | null } | null> {
  const patient = await prisma.patient.findUnique({
    where: { id: task.patientId },
    select: { active: true, doNotMessage: true, phoneE164: true },
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

/** Previews a recipient audience (used by the builder's preview step). */
export async function previewCampaignAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<
  ActionResult<{
    eligible: number;
    matched: number;
    overCap: boolean;
    groupSafe: boolean;
    groupBlockReasons: string[];
  }>
> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const filter = readRecipientFilter(formData);
    const preview = await previewCampaign(filter, {
      allowOverride: canOverrideGroupFilter(actor.role),
    });
    return ok({
      eligible: preview.eligible,
      matched: preview.matched,
      overCap: preview.overCap,
      groupSafe: preview.groupSafe,
      groupBlockReasons: preview.groupBlockReasons,
    });
  });
}

/** Reads the richer recipient filter from a form (accepts list + CSV fields). */
function readRecipientFilter(formData: FormData): RecipientFilterValues {
  const list = (name: string): string[] => {
    const values = formData.getAll(name).flatMap((value) => String(value).split(','));
    return [...new Set(values.map((value) => value.trim()).filter(Boolean))];
  };
  const one = (name: string, fallback = ''): string => String(formData.get(name) ?? fallback);
  return {
    tags: list('tags'),
    tagMode: one('tagMode', 'ANY') as RecipientFilterValues['tagMode'],
    serviceIds: list('serviceIds'),
    serviceCategory: one('serviceCategory') as RecipientFilterValues['serviceCategory'],
    language: one('language') as RecipientFilterValues['language'],
    lastVisitFrom: one('lastVisitFrom'),
    lastVisitTo: one('lastVisitTo'),
    boughtServiceFrom: one('boughtServiceFrom'),
    boughtServiceTo: one('boughtServiceTo'),
    upcoming: one('upcoming', 'ANY') as RecipientFilterValues['upcoming'],
    birthdayMonth: Number(one('birthdayMonth', '0')),
    handPickedIds: list('handPickedIds'),
    presetRange: one('presetRange') as RecipientFilterValues['presetRange'],
  };
}

export async function createCampaignAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string; created: number; batches: number }>> {
  return withAuth<{ id: string; created: number; batches: number }>(
    MESSAGE_ROLES,
    async (actor) => {
      const settings = await getSettingsMap();
      const receptionAllowed = settings.reception_can_send_campaigns === true;
      if (!canSendCampaigns(actor.role, receptionAllowed)) return fail('forbidden');

      const values = validate(campaignFormSchema, {
        ...readRecipientFilter(formData),
        title: formData.get('title') ?? '',
        templateKey: formData.get('templateKey') ?? '',
        customBody: formData.get('customBody') ?? '',
        libraryItemId: formData.get('libraryItemId') ?? '',
        languageMode: formData.get('languageMode') ?? 'PATIENT',
        fixedLanguage: formData.get('fixedLanguage') ?? 'en',
        deliveryMode: formData.get('deliveryMode') ?? 'INDIVIDUAL',
        expiresAt: formData.get('expiresAt') ?? '',
        acknowledgedGroupWarning: formData.get('acknowledgedGroupWarning') ?? 'false',
      });

      let customBody = (values.customBody ?? '').trim();
      let templateKey = values.templateKey;
      let libraryItemId: string | null = null;
      if (values.libraryItemId) {
        const item = await prisma.messageLibraryItem.findUnique({
          where: { id: values.libraryItemId },
          select: { id: true, body: true, active: true },
        });
        if (!item || !item.active) return fail('notFound');
        customBody = item.body;
        templateKey = 'CUSTOM';
        libraryItemId = item.id;
      }

      if (templateKey === 'CUSTOM' && !customBody) {
        return fail('validationError', { customBody: 'required' });
      }
      if (templateKey === 'CUSTOM') {
        const check = validateMessageBody(customBody);
        if (!check.ok && check.error) {
          return fail('validationError', { customBody: check.error });
        }
      }

      const finalTitle =
        (values.title ?? '').trim() || titleFromBody(customBody) || templateKey || 'Message';

      const filter: RecipientFilterValues = {
        tags: values.tags,
        tagMode: values.tagMode,
        serviceIds: values.serviceIds,
        serviceCategory: values.serviceCategory,
        language: values.language,
        lastVisitFrom: values.lastVisitFrom,
        lastVisitTo: values.lastVisitTo,
        boughtServiceFrom: values.boughtServiceFrom,
        boughtServiceTo: values.boughtServiceTo,
        upcoming: values.upcoming,
        birthdayMonth: values.birthdayMonth,
        handPickedIds: values.handPickedIds,
        presetRange: values.presetRange,
      };

      if (values.deliveryMode === 'GROUP') {
        const allowFiltered =
          canOverrideGroupFilter(actor.role) && settings.group_sms_allow_for_filtered === true;
        if (!groupAllowed(filter, allowFiltered)) return fail('groupNotAllowed');
        if (!values.acknowledgedGroupWarning) return fail('groupWarningRequired');
      }

      const expiresAt = values.expiresAt ? new Date(`${values.expiresAt}T23:59:00.000Z`) : null;
      const campaign = await prisma.campaign.create({
        data: {
          title: finalTitle,
          templateKey,
          customBody: templateKey === 'CUSTOM' ? customBody : null,
          languageMode: values.languageMode,
          fixedLanguage: values.languageMode === 'FIXED' ? values.fixedLanguage : null,
          filterJson: filter,
          deliveryMode: values.deliveryMode,
          libraryItemId,
          status: 'ACTIVE',
          expiresAt,
          createdBy: actor.id,
        },
        select: { id: true },
      });

      const result = await materializeCampaign({
        campaignId: campaign.id,
        campaignTitle: finalTitle,
        filter,
        template: {
          templateKey,
          customBody: customBody || null,
          languageMode: values.languageMode,
          fixedLanguage: values.fixedLanguage,
        },
        deliveryMode: values.deliveryMode,
        expiresAt,
      });

      if (result.overCap) {
        await prisma.campaign.update({ where: { id: campaign.id }, data: { status: 'CANCELLED' } });
        return fail('campaignOverCap');
      }

      if (libraryItemId) {
        await prisma.messageLibraryItem.update({
          where: { id: libraryItemId },
          data: { useCount: { increment: 1 }, lastUsedAt: new Date() },
        });
      }

      await writeAudit({
        user: actor,
        entity: 'Campaign',
        entityId: campaign.id,
        action: 'CAMPAIGN_ACTIVATED',
        new: {
          title: finalTitle,
          recipients: result.created,
          deliveryMode: values.deliveryMode,
          batches: result.batches,
          filter,
        },
      });
      return ok({ id: campaign.id, created: result.created, batches: result.batches });
    },
  );
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
      prisma.campaignBatch.updateMany({
        where: { campaignId: id, status: 'PENDING' },
        data: {
          status: 'SKIPPED',
          handledBy: actor.id,
          handledAt: new Date(),
          skipReason: 'cancelled',
        },
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

// ---------------------------------------------------------------------------
// Drafts (in-app composer — messages are always written here, never sent by
// the app itself; the receptionist still sends from the clinic phone).
// ---------------------------------------------------------------------------

export async function saveMessageDraftAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(MESSAGE_ROLES, async (actor) => {
    const values = validate(messageDraftSchema, {
      id: formData.get('id') ?? '',
      title: formData.get('title') ?? '',
      body: formData.get('body') ?? '',
      language: formData.get('language') ?? 'en',
    });
    const check = validateMessageBody(values.body);
    if (!check.ok && check.error) return fail('validationError', { body: check.error });

    const existing = values.id
      ? await prisma.messageDraft.findFirst({
          where: { id: values.id, createdBy: actor.id },
          select: { id: true },
        })
      : null;

    if (existing) {
      await prisma.messageDraft.update({
        where: { id: existing.id },
        data: { title: values.title || null, body: values.body, language: values.language },
      });
      await writeAudit({
        user: actor,
        entity: 'MessageDraft',
        entityId: existing.id,
        action: 'MESSAGE_DRAFT_UPDATED',
      });
      return ok({ id: existing.id });
    }

    const draft = await prisma.messageDraft.create({
      data: {
        createdBy: actor.id,
        title: values.title || null,
        body: values.body,
        language: values.language,
      },
      select: { id: true },
    });
    await writeAudit({
      user: actor,
      entity: 'MessageDraft',
      entityId: draft.id,
      action: 'MESSAGE_DRAFT_CREATED',
    });
    return ok({ id: draft.id });
  });
}

export async function deleteMessageDraftAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const draft = await prisma.messageDraft.findFirst({
      where: { id, createdBy: actor.id },
      select: { id: true },
    });
    if (!draft) return fail('notFound');
    await prisma.messageDraft.delete({ where: { id: draft.id } });
    await writeAudit({
      user: actor,
      entity: 'MessageDraft',
      entityId: draft.id,
      action: 'MESSAGE_DRAFT_DELETED',
    });
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Message Library (archive & reuse). Duplicate prevention is per language via
// the normalized hash; items are deactivated, never hard-deleted.
// ---------------------------------------------------------------------------

export async function saveLibraryItemAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(MESSAGE_ROLES, async (actor) => {
    const values = validate(libraryItemSchema, {
      id: formData.get('id') ?? '',
      title: formData.get('title') ?? '',
      body: formData.get('body') ?? '',
      language: formData.get('language') ?? 'en',
      category: formData.get('category') ?? 'REMINDER',
      isFavorite: formData.get('isFavorite') ?? 'false',
    });
    const check = validateMessageBody(values.body);
    if (!check.ok && check.error) return fail('validationError', { body: check.error });

    const hash = messageBodyHash(values.body);
    const title = (values.title ?? '').trim() || titleFromBody(values.body);

    const duplicate = await prisma.messageLibraryItem.findUnique({
      where: { normalizedHash_language: { normalizedHash: hash, language: values.language } },
      select: { id: true, active: true },
    });
    if (duplicate && duplicate.active && duplicate.id !== values.id) {
      return fail('libraryDuplicate');
    }

    const data = {
      title,
      body: values.body,
      language: values.language,
      category: values.category,
      normalizedHash: hash,
      isFavorite: values.isFavorite === true,
      active: true,
    };

    try {
      if (values.id) {
        const existing = await prisma.messageLibraryItem.findUnique({
          where: { id: values.id },
          select: { id: true },
        });
        if (!existing) return fail('notFound');
        await prisma.messageLibraryItem.update({ where: { id: values.id }, data });
        await writeAudit({
          user: actor,
          entity: 'MessageLibraryItem',
          entityId: values.id,
          action: 'MESSAGE_LIBRARY_UPDATED',
        });
        return ok({ id: values.id });
      }
      const item = await prisma.messageLibraryItem.create({
        data: { ...data, createdBy: actor.id },
        select: { id: true },
      });
      await writeAudit({
        user: actor,
        entity: 'MessageLibraryItem',
        entityId: item.id,
        action: 'MESSAGE_LIBRARY_CREATED',
        new: { title, language: values.language },
      });
      return ok({ id: item.id });
    } catch (err) {
      if (isUniqueViolation(err)) return fail('libraryDuplicate');
      throw err;
    }
  });
}

export async function toggleLibraryFavoriteAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const item = await prisma.messageLibraryItem.findUnique({
      where: { id },
      select: { id: true, isFavorite: true },
    });
    if (!item) return fail('notFound');
    await prisma.messageLibraryItem.update({
      where: { id },
      data: { isFavorite: !item.isFavorite },
    });
    void actor;
    return ok();
  });
}

export async function deleteLibraryItemAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const item = await prisma.messageLibraryItem.findUnique({
      where: { id },
      select: { id: true, active: true },
    });
    if (!item) return fail('notFound');
    if (!item.active) return ok();
    await prisma.messageLibraryItem.update({ where: { id }, data: { active: false } });
    await writeAudit({
      user: actor,
      entity: 'MessageLibraryItem',
      entityId: id,
      action: 'MESSAGE_LIBRARY_ARCHIVED',
    });
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Saved audiences (OWNER-managed reusable recipient filters)
// ---------------------------------------------------------------------------

export async function saveAudienceAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(MESSAGE_ROLES, async (actor) => {
    const audienceId = String(formData.get('audienceId') ?? '').trim();
    const values = validate(audienceFormSchema, {
      ...readRecipientFilter(formData),
      name: formData.get('name') ?? '',
    });
    const filter = {
      tags: values.tags,
      tagMode: values.tagMode,
      serviceIds: values.serviceIds,
      serviceCategory: values.serviceCategory,
      language: values.language,
      lastVisitFrom: values.lastVisitFrom,
      lastVisitTo: values.lastVisitTo,
      boughtServiceFrom: values.boughtServiceFrom,
      boughtServiceTo: values.boughtServiceTo,
      upcoming: values.upcoming,
      birthdayMonth: values.birthdayMonth,
      handPickedIds: values.handPickedIds,
      presetRange: values.presetRange,
    };

    if (audienceId) {
      const existing = await prisma.audience.findUnique({
        where: { id: audienceId },
        select: { id: true, createdBy: true },
      });
      if (!existing) return fail('notFound');
      await prisma.audience.update({
        where: { id: audienceId },
        data: { name: values.name, filterJson: filter },
      });
      await writeAudit({
        user: actor,
        entity: 'Audience',
        entityId: audienceId,
        action: 'AUDIENCE_UPDATED',
      });
      return ok({ id: audienceId });
    }

    const audience = await prisma.audience.create({
      data: { name: values.name, filterJson: filter, createdBy: actor.id },
      select: { id: true },
    });
    await writeAudit({
      user: actor,
      entity: 'Audience',
      entityId: audience.id,
      action: 'AUDIENCE_CREATED',
      new: { name: values.name },
    });
    return ok({ id: audience.id });
  });
}

export async function deleteAudienceAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const audience = await prisma.audience.findUnique({
      where: { id },
      select: { id: true },
    });
    if (!audience) return fail('notFound');
    await prisma.audience.delete({ where: { id } });
    await writeAudit({
      user: actor,
      entity: 'Audience',
      entityId: id,
      action: 'AUDIENCE_DELETED',
    });
    return ok();
  });
}

// ---------------------------------------------------------------------------
// Group-chat batches — mark a whole batch sent or skipped.
// ---------------------------------------------------------------------------

export async function setCampaignBatchStatusAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(MESSAGE_ROLES, async (actor) => {
    const batchId = String(formData.get('batchId') ?? '');
    const status = String(formData.get('status') ?? '');
    const reason = String(formData.get('reason') ?? '').trim().slice(0, 300);

    const batch = await prisma.campaignBatch.findUnique({
      where: { id: batchId },
      select: { id: true, status: true },
    });
    if (!batch) return fail('notFound');
    if (batch.status !== 'PENDING') return fail('batchAlreadyHandled');

    if (status === 'SENT') {
      await prisma.$transaction([
        prisma.campaignBatch.update({
          where: { id: batchId },
          data: { status: 'SENT', handledBy: actor.id, handledAt: new Date() },
        }),
        prisma.messageTask.updateMany({
          where: { campaignBatchId: batchId, status: { in: ['PENDING', 'OPENED'] } },
          data: { status: 'SENT', handledBy: actor.id, handledAt: new Date() },
        }),
      ]);
    } else if (status === 'SKIPPED') {
      if (!reason) return fail('validationError', { reason: 'required' });
      await prisma.$transaction([
        prisma.campaignBatch.update({
          where: { id: batchId },
          data: {
            status: 'SKIPPED',
            handledBy: actor.id,
            handledAt: new Date(),
            skipReason: reason,
          },
        }),
        prisma.messageTask.updateMany({
          where: { campaignBatchId: batchId, status: { in: ['PENDING', 'OPENED'] } },
          data: {
            status: 'SKIPPED',
            skipReason: reason,
            handledBy: actor.id,
            handledAt: new Date(),
          },
        }),
      ]);
    } else {
      return fail('validationError', { status: 'invalid' });
    }

    await writeAudit({
      user: actor,
      entity: 'CampaignBatch',
      entityId: batchId,
      action: 'CAMPAIGN_BATCH_HANDLED',
      new: { status, reason: reason || undefined },
    });
    return ok();
  });
}

function isUniqueViolation(err: unknown): boolean {
  return (
    typeof err === 'object' &&
    err !== null &&
    'code' in err &&
    (err as { code?: unknown }).code === 'P2002'
  );
}

/** Type-ahead search for the hand-picked patient picker. */
export async function searchPatientsAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<PickablePatient[]>> {
  void _prev;
  return withAuth<PickablePatient[]>(MESSAGE_ROLES, async () => {
    const query = String(formData.get('query') ?? '');
    return ok(await searchPickablePatients(query));
  });
}
