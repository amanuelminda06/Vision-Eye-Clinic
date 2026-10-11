import { Prisma, type MessageTemplateKey, type MessageSourceType } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { clinicDateKey, combineDateAndTime, dateKeyAdd, endOfDay } from '@/lib/datetime';
import { getSettingsMap, settingsFromMap } from '@/modules/settings';
import type { BlockedReason } from './constants';
import { blockedReason } from './eligibility';
import {
  appointmentConfirmationSchedule,
  appointmentReminderSchedule,
  appointmentSameDaySchedule,
  birthdaySchedule,
  followUpSchedule,
  immediateSchedule,
  noShowSchedule,
  recallPeriodKey,
  recallSchedule,
  thankYouSchedule,
  type AppointmentLike,
  type MessagingSettings,
} from './schedule';
import {
  bodyFor,
  loadTemplateBodies,
  renderMessage,
  resolveLanguage,
  type RenderContext,
} from './render';

/**
 * Idempotent task generation. `createTask` relies on the
 * (sourceType, sourceId, templateKey, patientId) unique index, so hooks and job
 * runs can repeat safely. Reschedule is the one case that intentionally makes a
 * NEW row (the old one is CANCELLED and a versioned sourceId is used).
 */

const SCHEDULE_KEYS: MessageTemplateKey[] = ['APPT_REMINDER', 'APPT_SAME_DAY', 'APPT_CONFIRMATION'];
const OPEN_STATUSES = ['PENDING', 'OPENED'] as const;

type PatientForMessaging = {
  id: string;
  fullName: string;
  phoneE164: string;
  preferredLanguage: string;
  consentSms: boolean;
  doNotMessage: boolean;
  active: boolean;
};

type MessagingConfig = {
  settings: MessagingSettings;
  clinicName: string;
  reviewLink: string;
};

async function loadConfig(): Promise<MessagingConfig> {
  const map = await getSettingsMap();
  const values = settingsFromMap(map);
  return {
    settings: {
      timeZone: values.timezone,
      reminderSendTime: values.reminder_send_time,
      reminderSendByOffsetMinutes: values.reminder_send_by_offset_minutes,
      followupLeadDays: values.followup_lead_days,
      recallMonths: values.recall_months,
    },
    clinicName: values.clinic_name,
    reviewLink: values.review_link,
  };
}

function isUniqueViolation(err: unknown): boolean {
  return err instanceof Prisma.PrismaClientKnownRequestError && err.code === 'P2002';
}

export type NewTaskInput = {
  patient: PatientForMessaging;
  templateKey: MessageTemplateKey;
  sourceType: MessageSourceType;
  sourceId: string;
  campaignId?: string | null;
  campaignBatchId?: string | null;
  language: string;
  renderedMessage: string;
  dueAt: Date;
  sendBy: Date | null;
  expiresAt: Date | null;
  sourceStatus?: string | null;
};

/** Creates one task unless the unique tuple already exists (returns existing). */
export async function createTask(input: NewTaskInput): Promise<'created' | 'exists'> {
  const reason: BlockedReason | null = blockedReason({
    patient: input.patient,
    sourceStatus: input.sourceStatus,
  });
  try {
    await prisma.messageTask.create({
      data: {
        patientId: input.patient.id,
        templateKey: input.templateKey,
        sourceType: input.sourceType,
        sourceId: input.sourceId,
        campaignId: input.campaignId ?? null,
        campaignBatchId: input.campaignBatchId ?? null,
        language: input.language,
        renderedMessage: input.renderedMessage,
        dueAt: input.dueAt,
        sendBy: input.sendBy,
        expiresAt: input.expiresAt,
        status: reason ? 'BLOCKED' : 'PENDING',
        blockedReason: reason,
      },
    });
    return 'created';
  } catch (err) {
    if (isUniqueViolation(err)) return 'exists';
    throw err;
  }
}

async function cancelPendingScope(
  sourceType: MessageSourceType,
  sourceIdPrefix: string,
  keys?: MessageTemplateKey[],
): Promise<number> {
  const result = await prisma.messageTask.updateMany({
    where: {
      sourceType,
      sourceId: { startsWith: sourceIdPrefix },
      status: { in: [...OPEN_STATUSES] },
      ...(keys ? { templateKey: { in: keys } } : {}),
    },
    data: { status: 'CANCELLED' },
  });
  return result.count;
}

/** Expires any open task whose deadline has passed. */
export async function expireTasks(now: Date = new Date()): Promise<number> {
  const result = await prisma.messageTask.updateMany({
    where: { status: { in: [...OPEN_STATUSES] }, expiresAt: { not: null, lt: now } },
    data: { status: 'EXPIRED' },
  });
  return result.count;
}

/** Cancels every pending task for a patient (used by the opt-out flow). */
export async function cancelPendingForPatient(patientId: string): Promise<number> {
  const result = await prisma.messageTask.updateMany({
    where: { patientId, status: { in: [...OPEN_STATUSES] } },
    data: { status: 'CANCELLED' },
  });
  return result.count;
}

// ---------------------------------------------------------------------------
// Appointment hook
// ---------------------------------------------------------------------------

export type AppointmentChangedPayload = {
  appointmentId: string;
  /** Set by the caller when the appointment time changed (reschedule). */
  previousStartsAt?: Date | null;
};

function appointmentContext(
  patient: PatientForMessaging,
  config: MessagingConfig,
  doctorName: string,
  startsAt: Date | null,
): RenderContext {
  return {
    patientName: patient.fullName,
    language: resolveLanguage(patient.preferredLanguage),
    clinicName: config.clinicName,
    reviewLink: config.reviewLink,
    startsAt,
    doctorName,
    timeZone: config.settings.timeZone,
  };
}

export async function onAppointmentChanged(payload: AppointmentChangedPayload): Promise<void> {
  const appointment = await prisma.appointment.findUnique({
    where: { id: payload.appointmentId },
    select: {
      id: true,
      status: true,
      startsAt: true,
      createdAt: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
      doctor: { select: { fullName: true } },
    },
  });
  if (!appointment) return;

  const config = await loadConfig();
  const bodies = await loadTemplateBodies();
  const now = new Date();
  const patient = appointment.patient;
  const apptLike: AppointmentLike = {
    id: appointment.id,
    startsAt: appointment.startsAt,
    createdAt: appointment.createdAt,
  };

  const rescheduled =
    payload.previousStartsAt != null &&
    payload.previousStartsAt.getTime() !== appointment.startsAt.getTime();
  if (rescheduled) {
    await cancelPendingScope('APPOINTMENT', appointment.id, ['APPT_REMINDER', 'APPT_SAME_DAY']);
  }

  const context = appointmentContext(
    patient,
    config,
    appointment.doctor.fullName,
    appointment.startsAt,
  );
  const source = { sourceType: 'APPOINTMENT' as const, sourceStatus: appointment.status };

  const makeTask = (
    key: MessageTemplateKey,
    sourceId: string,
    schedule: { dueAt: Date; sendBy: Date | null; expiresAt: Date | null } | null,
  ): Promise<'created' | 'exists'> | null => {
    if (!schedule) return null;
    return createTask({
      patient,
      templateKey: key,
      sourceId,
      language: context.language,
      renderedMessage: renderMessage(bodyFor(bodies, key, context.language), context),
      ...source,
      ...schedule,
    });
  };

  if (appointment.status === 'SCHEDULED' || appointment.status === 'CONFIRMED') {
    const versionId = `${appointment.id}#${appointment.startsAt.getTime()}`;
    await makeTask(
      'APPT_REMINDER',
      versionId,
      appointmentReminderSchedule(apptLike, config.settings, now),
    );
    await makeTask(
      'APPT_SAME_DAY',
      versionId,
      appointmentSameDaySchedule(apptLike, config.settings, now),
    );
    if (appointment.status === 'CONFIRMED') {
      await makeTask(
        'APPT_CONFIRMATION',
        appointment.id,
        appointmentConfirmationSchedule(now, config.settings),
      );
    }
    return;
  }

  if (appointment.status === 'COMPLETED') {
    await cancelPendingScope('APPOINTMENT', appointment.id, SCHEDULE_KEYS);
    const body = bodyFor(bodies, 'THANK_YOU', context.language);
    const schedule = thankYouSchedule(apptLike, config.settings);
    await createTask({
      patient,
      templateKey: 'THANK_YOU',
      sourceId: appointment.id,
      language: context.language,
      renderedMessage: renderMessage(body, context),
      ...source,
      ...schedule,
    });
    return;
  }

  if (appointment.status === 'NO_SHOW') {
    await cancelPendingScope('APPOINTMENT', appointment.id, SCHEDULE_KEYS);
    await createTask({
      patient,
      templateKey: 'NO_SHOW',
      sourceId: appointment.id,
      language: context.language,
      renderedMessage: renderMessage(bodyFor(bodies, 'NO_SHOW', context.language), context),
      ...source,
      ...noShowSchedule(now, config.settings),
    });
    return;
  }

  if (appointment.status === 'CANCELLED') {
    await cancelPendingScope('APPOINTMENT', appointment.id, SCHEDULE_KEYS);
  }
}

/** "Notify patient of change" button — creates an immediate schedule-change task. */
export async function generateScheduleChangeTask(
  appointmentId: string,
): Promise<'created' | 'exists' | 'missing'> {
  const appointment = await prisma.appointment.findUnique({
    where: { id: appointmentId },
    select: {
      id: true,
      startsAt: true,
      status: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
      doctor: { select: { fullName: true } },
    },
  });
  if (!appointment) return 'missing';
  const config = await loadConfig();
  const bodies = await loadTemplateBodies();
  const context = appointmentContext(
    appointment.patient,
    config,
    appointment.doctor.fullName,
    appointment.startsAt,
  );
  const sourceId = `${appointment.id}#sc#${Date.now()}`;
  return createTask({
    patient: appointment.patient,
    templateKey: 'SCHEDULE_CHANGE',
    sourceType: 'APPOINTMENT',
    sourceId,
    language: context.language,
    renderedMessage: renderMessage(bodyFor(bodies, 'SCHEDULE_CHANGE', context.language), context),
    sourceStatus: appointment.status,
    ...immediateSchedule(new Date()),
  });
}

// ---------------------------------------------------------------------------
// Follow-up hook
// ---------------------------------------------------------------------------

export type FollowUpChangedPayload = { followUpId: string };

export async function onFollowUpChanged(payload: FollowUpChangedPayload): Promise<void> {
  const followUp = await prisma.followUp.findUnique({
    where: { id: payload.followUpId },
    select: {
      id: true,
      dueDate: true,
      status: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
    },
  });
  if (!followUp) return;

  if (followUp.status !== 'OPEN') {
    await cancelPendingScope('FOLLOWUP', followUp.id, ['FOLLOWUP_DUE']);
    return;
  }

  const config = await loadConfig();
  const bodies = await loadTemplateBodies();
  const patient = followUp.patient;
  const context: RenderContext = {
    patientName: patient.fullName,
    language: resolveLanguage(patient.preferredLanguage),
    clinicName: config.clinicName,
    reviewLink: config.reviewLink,
    timeZone: config.settings.timeZone,
  };
  await createTask({
    patient,
    templateKey: 'FOLLOWUP_DUE',
    sourceType: 'FOLLOWUP',
    sourceId: followUp.id,
    language: context.language,
    renderedMessage: renderMessage(bodyFor(bodies, 'FOLLOWUP_DUE', context.language), context),
    sourceStatus: followUp.status,
    ...followUpSchedule(followUp.dueDate, config.settings, new Date()),
  });
}

/** Welcome message — fired when a patient's SMS consent first becomes true. */
export async function onConsentGranted(patientId: string): Promise<void> {
  const patient = await prisma.patient.findUnique({
    where: { id: patientId },
    select: {
      id: true,
      fullName: true,
      phoneE164: true,
      preferredLanguage: true,
      consentSms: true,
      doNotMessage: true,
      active: true,
    },
  });
  if (!patient) return;
  const config = await loadConfig();
  const bodies = await loadTemplateBodies();
  const language = resolveLanguage(patient.preferredLanguage);
  const context: RenderContext = {
    patientName: patient.fullName,
    language,
    clinicName: config.clinicName,
    reviewLink: config.reviewLink,
    timeZone: config.settings.timeZone,
  };
  await createTask({
    patient,
    templateKey: 'WELCOME',
    sourceType: 'MANUAL',
    sourceId: patient.id,
    language,
    renderedMessage: renderMessage(bodyFor(bodies, 'WELCOME', language), context),
    ...immediateSchedule(new Date()),
  });
}

// ---------------------------------------------------------------------------
// Scheduled job
// ---------------------------------------------------------------------------

export type JobSummary = {
  expired: number;
  sameDay: number;
  birthdays: number;
  thankYous: number;
  followUps: number;
  recalls: number;
  digestSent: boolean;
};

async function patientSelect(where: Prisma.PatientWhereInput) {
  return prisma.patient.findMany({
    where,
    select: {
      id: true,
      fullName: true,
      phoneE164: true,
      preferredLanguage: true,
      consentSms: true,
      doNotMessage: true,
      active: true,
      birthDate: true,
    },
    take: 1000,
  });
}

function asMessagingPatient(patient: {
  id: string;
  fullName: string;
  phoneE164: string;
  preferredLanguage: string;
  consentSms: boolean;
  doNotMessage: boolean;
  active: boolean;
}): PatientForMessaging {
  return {
    id: patient.id,
    fullName: patient.fullName,
    phoneE164: patient.phoneE164,
    preferredLanguage: patient.preferredLanguage,
    consentSms: patient.consentSms,
    doNotMessage: patient.doNotMessage,
    active: patient.active,
  };
}

async function generateSameDay(now: Date, config: MessagingConfig): Promise<number> {
  const todayKey = clinicDateKey(now, config.settings.timeZone);
  const appointments = await prisma.appointment.findMany({
    where: {
      status: { in: ['SCHEDULED', 'CONFIRMED'] },
      startsAt: {
        gte: combineDateAndTime(todayKey, '00:00', config.settings.timeZone),
        lt: combineDateAndTime(dateKeyAdd(todayKey, 1), '00:00', config.settings.timeZone),
      },
    },
    select: {
      id: true,
      startsAt: true,
      createdAt: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
      doctor: { select: { fullName: true } },
    },
    take: 500,
  });
  const bodies = await loadTemplateBodies();
  let created = 0;
  for (const appointment of appointments) {
    const schedule = appointmentSameDaySchedule(
      { id: appointment.id, startsAt: appointment.startsAt, createdAt: appointment.createdAt },
      config.settings,
      now,
    );
    if (!schedule) continue;
    const context = appointmentContext(
      appointment.patient,
      config,
      appointment.doctor.fullName,
      appointment.startsAt,
    );
    const result = await createTask({
      patient: appointment.patient,
      templateKey: 'APPT_SAME_DAY',
      sourceType: 'APPOINTMENT',
      sourceId: `${appointment.id}#${appointment.startsAt.getTime()}`,
      language: context.language,
      renderedMessage: renderMessage(bodyFor(bodies, 'APPT_SAME_DAY', context.language), context),
      sourceStatus: 'CONFIRMED',
      ...schedule,
    });
    if (result === 'created') created += 1;
  }
  return created;
}

async function generateBirthdays(now: Date, config: MessagingConfig): Promise<number> {
  const todayKey = clinicDateKey(now, config.settings.timeZone);
  const [, month, day] = todayKey.split('-').map(Number);
  const patients = await patientSelect({ birthDate: { not: null }, active: true });
  const candidates = patients.filter((patient) => {
    if (!patient.birthDate) return false;
    return patient.birthDate.getUTCMonth() + 1 === month && patient.birthDate.getUTCDate() === day;
  });
  const bodies = await loadTemplateBodies();
  const schedule = birthdaySchedule(now, config.settings);
  let created = 0;
  for (const patient of candidates) {
    const language = resolveLanguage(patient.preferredLanguage);
    const context: RenderContext = {
      patientName: patient.fullName,
      language,
      clinicName: config.clinicName,
      reviewLink: config.reviewLink,
      timeZone: config.settings.timeZone,
    };
    const result = await createTask({
      patient: asMessagingPatient(patient),
      templateKey: 'BIRTHDAY',
      sourceType: 'BIRTHDAY',
      sourceId: `${patient.id}#${todayKey}`,
      language,
      renderedMessage: renderMessage(bodyFor(bodies, 'BIRTHDAY', language), context),
      ...schedule,
    });
    if (result === 'created') created += 1;
  }
  return created;
}

async function generateThankYous(now: Date, config: MessagingConfig): Promise<number> {
  const yesterdayKey = dateKeyAdd(clinicDateKey(now, config.settings.timeZone), -1);
  const appointments = await prisma.appointment.findMany({
    where: {
      status: 'COMPLETED',
      startsAt: {
        gte: combineDateAndTime(yesterdayKey, '00:00', config.settings.timeZone),
        lt: combineDateAndTime(dateKeyAdd(yesterdayKey, 1), '00:00', config.settings.timeZone),
      },
    },
    select: {
      id: true,
      startsAt: true,
      createdAt: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
      doctor: { select: { fullName: true } },
    },
    take: 500,
  });
  const bodies = await loadTemplateBodies();
  let created = 0;
  for (const appointment of appointments) {
    const context = appointmentContext(
      appointment.patient,
      config,
      appointment.doctor.fullName,
      appointment.startsAt,
    );
    const result = await createTask({
      patient: appointment.patient,
      templateKey: 'THANK_YOU',
      sourceType: 'APPOINTMENT',
      sourceId: appointment.id,
      language: context.language,
      renderedMessage: renderMessage(bodyFor(bodies, 'THANK_YOU', context.language), context),
      sourceStatus: 'COMPLETED',
      ...thankYouSchedule(
        { id: appointment.id, startsAt: appointment.startsAt, createdAt: appointment.createdAt },
        config.settings,
      ),
    });
    if (result === 'created') created += 1;
  }
  return created;
}

async function generateFollowUps(now: Date, config: MessagingConfig): Promise<number> {
  const todayKey = clinicDateKey(now, config.settings.timeZone);
  const horizon = combineDateAndTime(
    dateKeyAdd(todayKey, config.settings.followupLeadDays + 1),
    '00:00',
    config.settings.timeZone,
  );
  const followUps = await prisma.followUp.findMany({
    where: { status: 'OPEN', dueDate: { lt: horizon } },
    select: {
      id: true,
      dueDate: true,
      patient: {
        select: {
          id: true,
          fullName: true,
          phoneE164: true,
          preferredLanguage: true,
          consentSms: true,
          doNotMessage: true,
          active: true,
        },
      },
    },
    take: 500,
  });
  const bodies = await loadTemplateBodies();
  let created = 0;
  for (const followUp of followUps) {
    const patient = followUp.patient;
    const language = resolveLanguage(patient.preferredLanguage);
    const context: RenderContext = {
      patientName: patient.fullName,
      language,
      clinicName: config.clinicName,
      reviewLink: config.reviewLink,
      timeZone: config.settings.timeZone,
    };
    const result = await createTask({
      patient,
      templateKey: 'FOLLOWUP_DUE',
      sourceType: 'FOLLOWUP',
      sourceId: followUp.id,
      language,
      renderedMessage: renderMessage(bodyFor(bodies, 'FOLLOWUP_DUE', language), context),
      sourceStatus: 'OPEN',
      ...followUpSchedule(followUp.dueDate, config.settings, now),
    });
    if (result === 'created') created += 1;
  }
  return created;
}

async function generateRecalls(now: Date, config: MessagingConfig): Promise<number> {
  const period = recallPeriodKey(now, config.settings.timeZone);
  const threshold = new Date(now);
  threshold.setUTCMonth(threshold.getUTCMonth() - config.settings.recallMonths);
  const patients = await patientSelect({ consentSms: true, doNotMessage: false, active: true });
  const bodies = await loadTemplateBodies();
  let created = 0;
  for (const patient of patients) {
    const [lastAppointment, lastSale, futureAppointment] = await Promise.all([
      prisma.appointment.findFirst({
        where: { patientId: patient.id, status: 'COMPLETED' },
        orderBy: { startsAt: 'desc' },
        select: { startsAt: true },
      }),
      prisma.sale.findFirst({
        where: { patientId: patient.id, status: 'CONFIRMED' },
        orderBy: { saleDate: 'desc' },
        select: { saleDate: true },
      }),
      prisma.appointment.count({
        where: {
          patientId: patient.id,
          status: { in: ['SCHEDULED', 'CONFIRMED'] },
          startsAt: { gt: now },
        },
      }),
    ]);
    if (futureAppointment > 0) continue;
    const lastActivity = [lastAppointment?.startsAt, lastSale?.saleDate]
      .filter((value): value is Date => value instanceof Date)
      .sort((a, b) => b.getTime() - a.getTime())[0];
    if (!lastActivity || lastActivity.getTime() > threshold.getTime()) continue;
    const language = resolveLanguage(patient.preferredLanguage);
    const context: RenderContext = {
      patientName: patient.fullName,
      language,
      clinicName: config.clinicName,
      reviewLink: config.reviewLink,
      timeZone: config.settings.timeZone,
    };
    const result = await createTask({
      patient: asMessagingPatient(patient),
      templateKey: 'RECALL',
      sourceType: 'RECALL',
      sourceId: `${patient.id}#${period}`,
      language,
      renderedMessage: renderMessage(bodyFor(bodies, 'RECALL', language), context),
      ...recallSchedule(now),
    });
    if (result === 'created') created += 1;
  }
  return created;
}

/** Runs every automatic generation step + expiry. Safe to run repeatedly. */
export async function runMessageJobs(now: Date = new Date()): Promise<JobSummary> {
  const config = await loadConfig();
  const expired = await expireTasks(now);
  const sameDay = await generateSameDay(now, config);
  const birthdays = await generateBirthdays(now, config);
  const thankYous = await generateThankYous(now, config);
  const followUps = await generateFollowUps(now, config);
  const recalls = await generateRecalls(now, config);
  const digestSent = await maybeSendDigest(now, config);
  return { expired, sameDay, birthdays, thankYous, followUps, recalls, digestSent };
}

async function maybeSendDigest(now: Date, config: MessagingConfig): Promise<boolean> {
  const map = await getSettingsMap();
  if (map.digest_enabled !== true) return false;
  const to = typeof map.digest_email === 'string' ? map.digest_email.trim() : '';
  if (!to) return false;
  const todayKey = clinicDateKey(now, config.settings.timeZone);
  const sendAt = combineDateAndTime(todayKey, '16:00', config.settings.timeZone);
  if (now.getTime() < sendAt.getTime()) return false;
  if (map.digest_last_sent === todayKey) return false;

  const due = await prisma.messageTask.count({
    where: {
      status: { in: [...OPEN_STATUSES] },
      dueAt: { lt: endOfDay(now, config.settings.timeZone) },
    },
  });
  const { getEmailProvider } = await import('./email');
  await getEmailProvider().send({
    to,
    subject: `Vision Eye Clinic — ${due} message(s) still unsent`,
    body: `${due} prepared message(s) are still waiting to be sent from the clinic phone.`,
  });
  await prisma.setting.upsert({
    where: { key: 'digest_last_sent' },
    update: { value: todayKey },
    create: { key: 'digest_last_sent', value: todayKey },
  });
  return true;
}
