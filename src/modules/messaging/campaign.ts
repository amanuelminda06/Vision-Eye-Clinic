import { prisma } from '@/lib/prisma';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  bodyFor,
  loadTemplateBodies,
  renderMessage,
  resolveLanguage,
  type RenderContext,
} from './render';
import { createTask } from './generate';
import type { CampaignLanguageMode, CampaignDelivery } from './constants';
import type { CampaignCandidate } from './campaign-filter';
import {
  normalizeRecipientFilter,
  resolvePresetRange,
  summarizeRecipients,
  type RecipientFilter,
} from './recipient-filter';
import { planBatches, resolveGroupBatchSize } from './group-plan';

/**
 * Campaign audience: DB candidate loader + materialisation. The pure matcher
 * lives in `recipient-filter.ts`.
 */

/** Resolves quick date presets against the clinic "today" before matching. */
function withResolvedPreset(filter: RecipientFilter, nowKey: string): RecipientFilter {
  const range = resolvePresetRange(filter, nowKey);
  return { ...filter, ...range };
}

/** Loads every patient (with tags, last visit, purchases, upcoming) for filtering. */
export async function loadCampaignCandidates(now: Date = new Date()): Promise<CampaignCandidate[]> {
  const [patients, completedAppointments, confirmedSales, upcoming, purchaseLines] =
    await Promise.all([
      prisma.patient.findMany({
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          doNotMessage: true,
          active: true,
          birthDate: true,
          tags: { select: { tag: { select: { name: true } } } },
        },
        take: 2000,
      }),
      prisma.appointment.findMany({
        where: { status: 'COMPLETED' },
        select: { patientId: true, startsAt: true },
        orderBy: { startsAt: 'desc' },
        take: 5000,
      }),
      prisma.sale.findMany({
        where: { status: 'CONFIRMED', patientId: { not: null } },
        select: { patientId: true, saleDate: true },
        orderBy: { saleDate: 'desc' },
        take: 5000,
      }),
      prisma.appointment.groupBy({
        by: ['patientId'],
        where: { status: { in: ['SCHEDULED', 'CONFIRMED'] }, startsAt: { gt: now } },
        _count: { _all: true },
      }),
      prisma.saleLine.findMany({
        where: { sale: { status: 'CONFIRMED', patientId: { not: null } } },
        select: { sale: { select: { patientId: true } }, service: { select: { id: true, category: true } } },
        take: 10000,
      }),
    ]);

  const lastAppointment = new Map<string, Date>();
  for (const row of completedAppointments) {
    if (!lastAppointment.has(row.patientId)) lastAppointment.set(row.patientId, row.startsAt);
  }
  const lastSale = new Map<string, Date>();
  for (const row of confirmedSales) {
    if (row.patientId && !lastSale.has(row.patientId)) lastSale.set(row.patientId, row.saleDate);
  }
  const upcomingSet = new Set(upcoming.map((row) => row.patientId));
  const categories = new Map<string, Set<string>>();
  const serviceIds = new Map<string, Set<string>>();
  for (const line of purchaseLines) {
    const patientId = line.sale.patientId;
    if (!patientId) continue;
    const set = categories.get(patientId) ?? new Set<string>();
    set.add(line.service.category);
    categories.set(patientId, set);
    const ids = serviceIds.get(patientId) ?? new Set<string>();
    ids.add(line.service.id);
    serviceIds.set(patientId, ids);
  }

  return patients.map((patient) => {
    const appointmentAt = lastAppointment.get(patient.id);
    const saleAt = lastSale.get(patient.id);
    const lastVisitAt =
      appointmentAt && saleAt
        ? appointmentAt.getTime() >= saleAt.getTime()
          ? appointmentAt
          : saleAt
        : (appointmentAt ?? saleAt ?? null);
    return {
      id: patient.id,
      fullName: patient.fullName,
      phoneE164: patient.phoneE164,
      preferredLanguage: patient.preferredLanguage,
      doNotMessage: patient.doNotMessage,
      active: patient.active,
      birthDate: patient.birthDate,
      tags: patient.tags.map((link) => link.tag.name),
      lastVisitAt,
      lastPurchaseAt: saleAt ?? null,
      hasUpcoming: upcomingSet.has(patient.id),
      categories: [...(categories.get(patient.id) ?? [])],
      serviceIds: [...(serviceIds.get(patient.id) ?? [])],
    };
  });
}

export async function previewCampaign(
  filter: RecipientFilter,
  options: { now?: Date; allowOverride?: boolean } = {},
): Promise<ReturnType<typeof summarizeRecipients>['preview']> {
  const now = options.now ?? new Date();
  const map = await getSettingsMap();
  const cap = Number(map.campaign_max_recipients ?? 300);
  const allow = (options.allowOverride ?? false) && map.group_sms_allow_for_filtered === true;
  const candidates = await loadCampaignCandidates(now);
  const resolved = withResolvedPreset(filter, clinicDateKey(now, timezoneFrom(map)));
  return summarizeRecipients(candidates, resolved, cap, allow).preview;
}

export type CampaignTemplate = {
  templateKey: string;
  customBody: string | null;
  languageMode: CampaignLanguageMode;
  fixedLanguage: string;
};

/** Renders the campaign message for one candidate in the right language. */
export function campaignMessage(
  bodies: Map<string, string>,
  template: CampaignTemplate,
  candidate: CampaignCandidate,
  clinicName: string,
  reviewLink: string,
  timeZone: string,
): { language: string; message: string } {
  const language =
    template.languageMode === 'FIXED'
      ? resolveLanguage(template.fixedLanguage)
      : resolveLanguage(candidate.preferredLanguage);
  const context: RenderContext = {
    patientName: candidate.fullName,
    language,
    clinicName,
    reviewLink,
    timeZone,
  };
  const body =
    template.customBody && template.customBody.trim()
      ? template.customBody
      : bodyFor(bodies, template.templateKey as never, language);
  return { language, message: renderMessage(body, context) };
}

export type CreateCampaignResult = {
  eligible: number;
  created: number;
  overCap: boolean;
  batches: number;
};

/**
 * Creates one MessageTask per eligible recipient (never one group message). For
 * GROUP delivery the tasks are also grouped into `CampaignBatch` rows so the
 * queue can open the phone's SMS app once per batch.
 */
export async function materializeCampaign(params: {
  campaignId: string;
  campaignTitle: string;
  filter: RecipientFilter;
  template: CampaignTemplate;
  deliveryMode: CampaignDelivery;
  expiresAt: Date | null;
  now?: Date;
}): Promise<CreateCampaignResult> {
  const now = params.now ?? new Date();
  const map = await getSettingsMap();
  const cap = Number(map.campaign_max_recipients ?? 300);
  const tz = timezoneFrom(map);
  const candidates = await loadCampaignCandidates(now);
  const filter = withResolvedPreset(params.filter, clinicDateKey(now, tz));
  const { eligible, preview } = summarizeRecipients(candidates, filter, cap, true);
  if (preview.overCap) return { eligible: eligible.length, created: 0, overCap: true, batches: 0 };

  const bodies = await loadTemplateBodies();
  const clinicName = typeof map.clinic_name === 'string' ? map.clinic_name : 'Vision Eye Clinic';
  const reviewLink = typeof map.review_link === 'string' ? map.review_link : '';

  const batchSize =
    params.deliveryMode === 'GROUP' ? resolveGroupBatchSize(map.group_sms_max_recipients) : 1;
  const sizes = params.deliveryMode === 'GROUP' ? planBatches(eligible.length, batchSize) : [];
  const batchIdByNumber = new Map<number, string>();
  for (let index = 0; index < sizes.length; index += 1) {
    const batch = await prisma.campaignBatch.create({
      data: {
        campaignId: params.campaignId,
        batchNumber: index + 1,
        size: sizes[index],
      },
      select: { id: true },
    });
    batchIdByNumber.set(index + 1, batch.id);
  }

  let created = 0;
  let recipientIndex = 0;
  for (const candidate of eligible) {
    const { language, message } = campaignMessage(
      bodies,
      params.template,
      candidate,
      clinicName,
      reviewLink,
      tz,
    );
    const batchNumber = params.deliveryMode === 'GROUP' ? Math.floor(recipientIndex / batchSize) + 1 : 0;
    recipientIndex += 1;
    const result = await createTask({
      patient: {
        id: candidate.id,
        fullName: candidate.fullName,
        phoneE164: candidate.phoneE164,
        preferredLanguage: candidate.preferredLanguage,
        doNotMessage: candidate.doNotMessage,
        active: candidate.active,
      },
      templateKey: params.template.templateKey as never,
      sourceType: 'CAMPAIGN',
      sourceId: params.campaignId,
      campaignId: params.campaignId,
      campaignBatchId:
        params.deliveryMode === 'GROUP' ? (batchIdByNumber.get(batchNumber) ?? null) : null,
      language,
      renderedMessage: message,
      dueAt: now,
      sendBy: null,
      expiresAt: params.expiresAt,
    });
    if (result === 'created') created += 1;
  }
  return { eligible: eligible.length, created, overCap: false, batches: sizes.length };
}

export { clinicDateKey, normalizeRecipientFilter };
