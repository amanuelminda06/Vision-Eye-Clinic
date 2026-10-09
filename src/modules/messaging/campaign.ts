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
import type { CampaignLanguageMode } from './constants';
import type { CampaignFilterValues } from './schemas';
import {
  summarizeCandidates,
  type CampaignCandidate,
  type CampaignPreview,
} from './campaign-filter';

/**
 * Campaign audience: DB candidate loader + materialisation. The pure matcher
 * lives in `campaign-filter.ts`.
 */

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
          consentSms: true,
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
        select: { sale: { select: { patientId: true } }, service: { select: { category: true } } },
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
  for (const line of purchaseLines) {
    const patientId = line.sale.patientId;
    if (!patientId) continue;
    const set = categories.get(patientId) ?? new Set<string>();
    set.add(line.service.category);
    categories.set(patientId, set);
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
      consentSms: patient.consentSms,
      doNotMessage: patient.doNotMessage,
      active: patient.active,
      birthDate: patient.birthDate,
      tags: patient.tags.map((link) => link.tag.name),
      lastVisitAt,
      hasUpcoming: upcomingSet.has(patient.id),
      categories: [...(categories.get(patient.id) ?? [])],
    };
  });
}

export async function previewCampaign(
  filter: CampaignFilterValues,
  now: Date = new Date(),
): Promise<CampaignPreview> {
  const map = await getSettingsMap();
  const cap = Number(map.campaign_max_recipients ?? 300);
  const candidates = await loadCampaignCandidates(now);
  return summarizeCandidates(candidates, filter, cap).preview;
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
};

/** Creates one MessageTask per eligible recipient. Never one group message. */
export async function materializeCampaign(params: {
  campaignId: string;
  campaignTitle: string;
  filter: CampaignFilterValues;
  template: CampaignTemplate;
  expiresAt: Date | null;
  now?: Date;
}): Promise<CreateCampaignResult> {
  const now = params.now ?? new Date();
  const map = await getSettingsMap();
  const cap = Number(map.campaign_max_recipients ?? 300);
  const candidates = await loadCampaignCandidates(now);
  const { eligible, preview } = summarizeCandidates(candidates, params.filter, cap);
  if (preview.overCap) return { eligible: eligible.length, created: 0, overCap: true };

  const bodies = await loadTemplateBodies();
  const tz = timezoneFrom(map);
  const clinicName = typeof map.clinic_name === 'string' ? map.clinic_name : 'Vision Eye Clinic';
  const reviewLink = typeof map.review_link === 'string' ? map.review_link : '';

  let created = 0;
  for (const candidate of eligible) {
    const { language, message } = campaignMessage(
      bodies,
      params.template,
      candidate,
      clinicName,
      reviewLink,
      tz,
    );
    const result = await createTask({
      patient: {
        id: candidate.id,
        fullName: candidate.fullName,
        phoneE164: candidate.phoneE164,
        preferredLanguage: candidate.preferredLanguage,
        consentSms: candidate.consentSms,
        doNotMessage: candidate.doNotMessage,
        active: candidate.active,
      },
      templateKey: params.template.templateKey as never,
      sourceType: 'CAMPAIGN',
      sourceId: params.campaignId,
      campaignId: params.campaignId,
      language,
      renderedMessage: message,
      dueAt: now,
      sendBy: null,
      expiresAt: params.expiresAt,
    });
    if (result === 'created') created += 1;
  }
  return { eligible: eligible.length, created, overCap: false };
}

export { clinicDateKey };
