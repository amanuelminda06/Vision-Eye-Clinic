import type { MessageStatus, MessageTemplateKey, MessageSourceType } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { clinicDateKey, combineDateAndTime, dateKeyAdd, endOfDay } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';

/** Read-side queries for the outbox, patient tab, reports, and campaigns. */

export type MessageTaskRow = {
  id: string;
  patientId: string;
  patientName: string;
  patientPhoneE164: string;
  templateKey: MessageTemplateKey;
  sourceType: MessageSourceType;
  sourceId: string;
  campaignId: string | null;
  language: string;
  renderedMessage: string;
  dueAt: Date;
  sendBy: Date | null;
  expiresAt: Date | null;
  status: MessageStatus;
  blockedReason: string | null;
  skipReason: string | null;
  handledBy: string | null;
  handledAt: Date | null;
  editedAt: Date | null;
  createdAt: Date;
  /** Timestamp of the appointment/follow-up this task refers to, if any. */
  sourceAt: Date | null;
};

const TASK_SELECT = {
  id: true,
  patientId: true,
  templateKey: true,
  sourceType: true,
  sourceId: true,
  campaignId: true,
  language: true,
  renderedMessage: true,
  dueAt: true,
  sendBy: true,
  expiresAt: true,
  status: true,
  blockedReason: true,
  skipReason: true,
  handledBy: true,
  handledAt: true,
  editedAt: true,
  createdAt: true,
  patient: { select: { fullName: true, phoneE164: true } },
} as const;

type TaskSelectRow = {
  id: string;
  patientId: string;
  templateKey: MessageTemplateKey;
  sourceType: MessageSourceType;
  sourceId: string;
  campaignId: string | null;
  language: string;
  renderedMessage: string;
  dueAt: Date;
  sendBy: Date | null;
  expiresAt: Date | null;
  status: MessageStatus;
  blockedReason: string | null;
  skipReason: string | null;
  handledBy: string | null;
  handledAt: Date | null;
  editedAt: Date | null;
  createdAt: Date;
  patient: { fullName: string; phoneE164: string };
};

function appointmentIdFromSource(sourceId: string): string {
  return sourceId.split('#')[0];
}

async function decorate(rows: TaskSelectRow[]): Promise<MessageTaskRow[]> {
  const appointmentIds = new Set<string>();
  const followUpIds = new Set<string>();
  for (const row of rows) {
    if (row.sourceType === 'APPOINTMENT') appointmentIds.add(appointmentIdFromSource(row.sourceId));
    if (row.sourceType === 'FOLLOWUP') followUpIds.add(row.sourceId);
  }

  const [appointments, followUps] = await Promise.all([
    appointmentIds.size
      ? prisma.appointment.findMany({
          where: { id: { in: [...appointmentIds] } },
          select: { id: true, startsAt: true },
        })
      : Promise.resolve([]),
    followUpIds.size
      ? prisma.followUp.findMany({
          where: { id: { in: [...followUpIds] } },
          select: { id: true, dueDate: true },
        })
      : Promise.resolve([]),
  ]);
  const appointmentAt = new Map(appointments.map((row) => [row.id, row.startsAt]));
  const followUpAt = new Map(followUps.map((row) => [row.id, row.dueDate]));

  return rows.map((row) => {
    const sourceAt =
      row.sourceType === 'APPOINTMENT'
        ? (appointmentAt.get(appointmentIdFromSource(row.sourceId)) ?? null)
        : row.sourceType === 'FOLLOWUP'
          ? (followUpAt.get(row.sourceId) ?? null)
          : null;
    return {
      ...row,
      patientName: row.patient.fullName,
      patientPhoneE164: row.patient.phoneE164,
      sourceAt,
    };
  });
}

export type OutboxGroups = {
  dueNow: MessageTaskRow[];
  laterToday: MessageTaskRow[];
  overdue: MessageTaskRow[];
  doneToday: MessageTaskRow[];
  blocked: MessageTaskRow[];
};

/** Today's message queue, grouped for the Outbox page. */
export async function listOutbox(now: Date = new Date()): Promise<OutboxGroups> {
  const map = await getSettingsMap();
  const tz = timezoneFrom(map);
  const todayKey = clinicDateKey(now, tz);
  const dayStart = combineDateAndTime(todayKey, '00:00', tz);
  const dayEnd = endOfDay(now, tz);

  const rows = await prisma.messageTask.findMany({
    where: {
      OR: [
        { status: { in: ['PENDING', 'OPENED'] }, dueAt: { lt: dayEnd } },
        { status: 'BLOCKED' },
        { status: { in: ['SENT', 'SKIPPED'] }, handledAt: { gte: dayStart, lt: dayEnd } },
      ],
    },
    select: TASK_SELECT,
    orderBy: [{ dueAt: 'asc' }],
    take: 500,
  });
  const decorated = await decorate(rows as TaskSelectRow[]);

  const groups: OutboxGroups = {
    dueNow: [],
    laterToday: [],
    overdue: [],
    doneToday: [],
    blocked: [],
  };
  for (const row of decorated) {
    if (row.status === 'BLOCKED') groups.blocked.push(row);
    else if (row.status === 'SENT' || row.status === 'SKIPPED') groups.doneToday.push(row);
    else if (row.dueAt.getTime() < dayStart.getTime()) groups.overdue.push(row);
    else if (row.dueAt.getTime() <= now.getTime()) groups.dueNow.push(row);
    else groups.laterToday.push(row);
  }
  return groups;
}

/** PENDING/OPENED tasks that are due now or overdue — the sidebar badge. */
export async function countDueTasks(now: Date = new Date()): Promise<number> {
  return prisma.messageTask.count({
    where: { status: { in: ['PENDING', 'OPENED'] }, dueAt: { lte: now } },
  });
}

export type DueTodayCounts = { dueToday: number; overdue: number };

/** Dashboard banner numbers. */
export async function countDueToday(now: Date = new Date()): Promise<DueTodayCounts> {
  const map = await getSettingsMap();
  const tz = timezoneFrom(map);
  const todayKey = clinicDateKey(now, tz);
  const dayStart = combineDateAndTime(todayKey, '00:00', tz);
  const dayEnd = endOfDay(now, tz);
  const [dueToday, overdue] = await Promise.all([
    prisma.messageTask.count({
      where: {
        status: { in: ['PENDING', 'OPENED'] },
        dueAt: { gte: dayStart, lt: dayEnd },
      },
    }),
    prisma.messageTask.count({
      where: { status: { in: ['PENDING', 'OPENED'] }, dueAt: { lt: dayStart } },
    }),
  ]);
  return { dueToday, overdue };
}

export async function getMessageTask(id: string): Promise<MessageTaskRow | null> {
  const row = await prisma.messageTask.findUnique({ where: { id }, select: TASK_SELECT });
  if (!row) return null;
  const [decorated] = await decorate([row as TaskSelectRow]);
  return decorated ?? null;
}

export async function listPatientMessages(patientId: string): Promise<MessageTaskRow[]> {
  const rows = await prisma.messageTask.findMany({
    where: { patientId },
    select: TASK_SELECT,
    orderBy: { createdAt: 'desc' },
    take: 200,
  });
  return decorate(rows as TaskSelectRow[]);
}

export async function countPatientMessages(patientId: string): Promise<number> {
  return prisma.messageTask.count({ where: { patientId } });
}

export type ExpiredDayReport = {
  dayKey: string;
  count: number;
  tasks: MessageTaskRow[];
};

/** OWNER report: tasks that expired without being sent, grouped by day. */
export async function expiredReport(): Promise<ExpiredDayReport[]> {
  const map = await getSettingsMap();
  const tz = timezoneFrom(map);
  const rows = await prisma.messageTask.findMany({
    where: { status: 'EXPIRED' },
    select: TASK_SELECT,
    orderBy: { expiresAt: 'desc' },
    take: 500,
  });
  const decorated = await decorate(rows as TaskSelectRow[]);
  const byDay = new Map<string, MessageTaskRow[]>();
  for (const row of decorated) {
    const dayKey = clinicDateKey(row.expiresAt ?? row.dueAt, tz);
    byDay.set(dayKey, [...(byDay.get(dayKey) ?? []), row]);
  }
  return [...byDay.entries()]
    .sort((a, b) => (a[0] < b[0] ? 1 : -1))
    .map(([dayKey, tasks]) => ({ dayKey, count: tasks.length, tasks }));
}

export { clinicDateKey, dateKeyAdd };

// ---------------------------------------------------------------------------
// Campaigns
// ---------------------------------------------------------------------------

export type CampaignRow = {
  id: string;
  title: string;
  templateKey: MessageTemplateKey | null;
  languageMode: string;
  deliveryMode: 'INDIVIDUAL' | 'GROUP';
  status: string;
  expiresAt: Date | null;
  createdAt: Date;
  total: number;
  sent: number;
  pending: number;
  skipped: number;
  failed: number;
};

async function campaignCounts(campaignId: string) {
  const rows = await prisma.messageTask.groupBy({
    by: ['status'],
    where: { campaignId },
    _count: { _all: true },
  });
  const count = (status: MessageStatus) =>
    rows.find((row) => row.status === status)?._count._all ?? 0;
  return {
    total: rows.reduce((sum, row) => sum + row._count._all, 0),
    sent: count('SENT'),
    pending: count('PENDING') + count('OPENED'),
    skipped: count('SKIPPED'),
    failed: count('BLOCKED') + count('EXPIRED') + count('CANCELLED'),
  };
}

export async function listCampaigns(): Promise<CampaignRow[]> {
  const rows = await prisma.campaign.findMany({
    orderBy: { createdAt: 'desc' },
    take: 100,
    select: {
      id: true,
      title: true,
      templateKey: true,
      languageMode: true,
      deliveryMode: true,
      status: true,
      expiresAt: true,
      createdAt: true,
    },
  });
  return Promise.all(rows.map(async (row) => ({ ...row, ...(await campaignCounts(row.id)) })));
}

export async function getCampaign(id: string): Promise<CampaignRow | null> {
  const row = await prisma.campaign.findUnique({
    where: { id },
    select: {
      id: true,
      title: true,
      templateKey: true,
      languageMode: true,
      deliveryMode: true,
      status: true,
      expiresAt: true,
      createdAt: true,
    },
  });
  if (!row) return null;
  return { ...row, ...(await campaignCounts(row.id)) };
}

export type CampaignQueueItem = {
  id: string;
  patientName: string;
  patientPhoneE164: string;
  renderedMessage: string;
  dueAt: Date;
  status: MessageStatus;
  blockedReason: string | null;
};

/** Pending + handled tasks for one campaign, for the "Send next" queue. */
export async function listCampaignQueue(campaignId: string): Promise<CampaignQueueItem[]> {
  const rows = await prisma.messageTask.findMany({
    where: { campaignId },
    select: {
      id: true,
      renderedMessage: true,
      dueAt: true,
      status: true,
      blockedReason: true,
      patient: { select: { fullName: true, phoneE164: true } },
    },
    orderBy: [{ status: 'asc' }, { dueAt: 'asc' }],
    take: 500,
  });
  return rows.map((row) => ({
    id: row.id,
    patientName: row.patient.fullName,
    patientPhoneE164: row.patient.phoneE164,
    renderedMessage: row.renderedMessage,
    dueAt: row.dueAt,
    status: row.status,
    blockedReason: row.blockedReason,
  }));
}

export type TemplateRow = {
  id: string;
  key: MessageTemplateKey;
  language: string;
  body: string;
  active: boolean;
};

export async function listTemplates(): Promise<TemplateRow[]> {
  return prisma.messageTemplate.findMany({
    select: { id: true, key: true, language: true, body: true, active: true },
    orderBy: [{ key: 'asc' }, { language: 'asc' }],
  });
}

export async function getTemplate(id: string): Promise<TemplateRow | null> {
  return prisma.messageTemplate.findUnique({
    where: { id },
    select: { id: true, key: true, language: true, body: true, active: true },
  });
}

export async function countOptedOutPatients(): Promise<number> {
  return prisma.patient.count({ where: { doNotMessage: true } });
}

// ---------------------------------------------------------------------------
// Drafts, Message Library, Saved audiences, Group batches
// ---------------------------------------------------------------------------

export type DraftRow = {
  id: string;
  title: string | null;
  body: string;
  language: string;
  updatedAt: Date;
};

export async function listDrafts(createdBy: string): Promise<DraftRow[]> {
  return prisma.messageDraft.findMany({
    where: { createdBy },
    select: { id: true, title: true, body: true, language: true, updatedAt: true },
    orderBy: { updatedAt: 'desc' },
    take: 100,
  });
}

export async function getDraft(id: string): Promise<DraftRow | null> {
  return prisma.messageDraft.findUnique({
    where: { id },
    select: { id: true, title: true, body: true, language: true, updatedAt: true },
  });
}

export type LibraryRow = {
  id: string;
  title: string;
  body: string;
  language: string;
  category: string;
  isFavorite: boolean;
  useCount: number;
  lastUsedAt: Date | null;
  updatedAt: Date;
};

export async function listLibraryItems(options?: {
  language?: string;
  category?: string;
  search?: string;
  favorites?: boolean;
  includeArchived?: boolean;
}): Promise<LibraryRow[]> {
  const where: Record<string, unknown> = {};
  if (!options?.includeArchived) where.active = true;
  if (options?.language) where.language = options.language;
  if (options?.category) where.category = options.category;
  if (options?.favorites) where.isFavorite = true;
  if (options?.search) {
    where.OR = [
      { title: { contains: options.search, mode: 'insensitive' } },
      { body: { contains: options.search, mode: 'insensitive' } },
    ];
  }
  return prisma.messageLibraryItem.findMany({
    where,
    select: {
      id: true,
      title: true,
      body: true,
      language: true,
      category: true,
      isFavorite: true,
      useCount: true,
      lastUsedAt: true,
      updatedAt: true,
    },
    orderBy: [{ isFavorite: 'desc' }, { useCount: 'desc' }, { updatedAt: 'desc' }],
    take: 200,
  });
}

export type AudienceRow = {
  id: string;
  name: string;
  filterJson: unknown;
  createdAt: Date;
};

export async function listAudiences(): Promise<AudienceRow[]> {
  return prisma.audience.findMany({
    select: { id: true, name: true, filterJson: true, createdAt: true },
    orderBy: { name: 'asc' },
    take: 100,
  });
}

export type CampaignBatchRow = {
  id: string;
  batchNumber: number;
  size: number;
  status: 'PENDING' | 'SENT' | 'SKIPPED';
  phones: string[];
  message: string;
  sample: { fullName: string; phoneE164: string }[];
};

/** Group batches for a campaign, each with its recipient numbers + shared text. */
export async function listCampaignBatches(campaignId: string): Promise<CampaignBatchRow[]> {
  const batches = await prisma.campaignBatch.findMany({
    where: { campaignId },
    orderBy: { batchNumber: 'asc' },
    select: {
      id: true,
      batchNumber: true,
      size: true,
      status: true,
      tasks: {
        select: {
          renderedMessage: true,
          patient: { select: { fullName: true, phoneE164: true } },
        },
        take: 5,
      },
    },
    take: 50,
  });

  const phoneRows = await prisma.messageTask.findMany({
    where: { campaignBatchId: { in: batches.map((batch) => batch.id) } },
    select: { campaignBatchId: true, patient: { select: { phoneE164: true } } },
  });
  const phonesByBatch = new Map<string, string[]>();
  for (const row of phoneRows) {
    if (!row.campaignBatchId) continue;
    phonesByBatch.set(row.campaignBatchId, [
      ...(phonesByBatch.get(row.campaignBatchId) ?? []),
      row.patient.phoneE164,
    ]);
  }

  return batches.map((batch) => ({
    id: batch.id,
    batchNumber: batch.batchNumber,
    size: batch.size,
    status: batch.status,
    phones: phonesByBatch.get(batch.id) ?? [],
    message: batch.tasks[0]?.renderedMessage ?? '',
    sample: batch.tasks.map((task) => task.patient),
  }));
}
