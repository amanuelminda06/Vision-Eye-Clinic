import { prisma } from '@/lib/prisma';
import { clinicTimeKey, clinicDateKey, formatInTz } from '@/lib/datetime';
import { renderTemplate } from './templates';
import { isMessageLanguage, type MessageLanguage, type TemplateVariable } from './constants';
import type { MessageTemplateKey } from '@prisma/client';

/** Loads every template body into a `${key}:${language}` map. */
export async function loadTemplateBodies(): Promise<Map<string, string>> {
  const rows = await prisma.messageTemplate.findMany({
    where: { active: true },
    select: { key: true, language: true, body: true },
  });
  return new Map(rows.map((row) => [`${row.key}:${row.language}`, row.body]));
}

/** Body for a key+language, falling back to English, then to ''. */
export function bodyFor(
  bodies: Map<string, string>,
  key: MessageTemplateKey,
  language: string,
): string {
  return bodies.get(`${key}:${language}`) ?? bodies.get(`${key}:en`) ?? '';
}

export type RenderContext = {
  patientName: string;
  language: string;
  clinicName: string;
  reviewLink: string;
  startsAt?: Date | null;
  doctorName?: string | null;
  reopenDate?: string | null;
  timeZone: string;
};

function dateLabel(date: Date, timeZone: string): string {
  return formatInTz(date, timeZone, { weekday: 'short', day: 'numeric', month: 'short' });
}

/** Builds the variable map for one task from its source context. */
export function renderVariables(context: RenderContext): Partial<Record<TemplateVariable, string>> {
  const variables: Partial<Record<TemplateVariable, string>> = {
    name: context.patientName,
    clinic: context.clinicName,
    review_link: context.reviewLink,
    ...(context.doctorName ? { doctor: context.doctorName } : {}),
    ...(context.reopenDate ? { reopen_date: context.reopenDate } : {}),
  };
  if (context.startsAt) {
    variables.date = dateLabel(context.startsAt, context.timeZone);
    variables.time = clinicTimeKey(context.startsAt, context.timeZone);
  }
  return variables;
}

export function renderMessage(body: string, context: RenderContext): string {
  return renderTemplate(body, renderVariables(context));
}

export function resolveLanguage(preferred: string | null | undefined): MessageLanguage {
  return isMessageLanguage(preferred) ? preferred : 'en';
}

export { clinicDateKey };
