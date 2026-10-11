import {
  GSM7_BASIC,
  GSM7_EXTENSION,
  MAX_CAMPAIGN_MESSAGE,
  TEMPLATE_VARIABLES,
  type TemplateVariable,
} from './constants';

/**
 * Pure template rules: variable validation, rendering, and the SMS character
 * counter (GSM-7 vs Unicode). No DB / no React — unit-tested directly.
 */

const VARIABLE_RE = /\{([^{}]*)\}/g;

/** Variable names referenced in a body, in order, de-duplicated. */
export function templateVariables(body: string): string[] {
  const found: string[] = [];
  for (const match of body.matchAll(VARIABLE_RE)) {
    const name = match[1];
    if (!found.includes(name)) found.push(name);
  }
  return found;
}

/** Unknown `{variable}` placeholders — an empty array means the body is valid. */
export function unknownVariables(body: string): string[] {
  const allowed = TEMPLATE_VARIABLES as readonly string[];
  return templateVariables(body).filter((name) => !allowed.includes(name));
}

/**
 * Whether the body uses only allowed variables. A stray `{`/`}` (GSM-7
 * extension chars) is allowed as literal text, so only `{name}` shapes count.
 */
export function validateTemplateBody(body: string): { ok: boolean; unknown: string[] } {
  const unknown = unknownVariables(body);
  return { ok: unknown.length === 0, unknown };
}

export type TemplateSample = Record<TemplateVariable, string>;

export const SAMPLE_DATA: TemplateSample = {
  name: 'Hemnet Tesfaye',
  date: 'Mon 12 Oct',
  time: '10:00',
  doctor: 'Dr. Abebe Kebede',
  clinic: 'Vision Eye Clinic',
  review_link: 'https://g.page/vision-eye-clinic/review',
  reopen_date: 'Mon 19 Oct',
};

/**
 * Substitutes known variables. When `review_link` is empty and the body
 * references it, the whole line is dropped (the "variant without it" rule).
 */
export function renderTemplate(body: string, data: Partial<TemplateSample>): string {
  const reviewEmpty = !data.review_link;
  const lines = body.split('\n').filter((line) => !(reviewEmpty && line.includes('{review_link}')));
  return lines.join('\n').replace(VARIABLE_RE, (_match, name: string) => {
    if (!(TEMPLATE_VARIABLES as readonly string[]).includes(name)) return '';
    const value = (data as Record<string, string | undefined>)[name];
    return value ?? '';
  });
}

export type SmsEncoding = 'GSM-7' | 'Unicode';

export type SmsCount = {
  encoding: SmsEncoding;
  /** Logical characters (GSM-7 extension chars count as 2). */
  length: number;
  /** Characters available in a single part. */
  perPart: number;
  /** Number of SMS parts (>= 1). */
  parts: number;
};

/**
 * Counts a message the way carrier handsets do: GSM-7 (160/153) for the
 * Latin/symbol set, Unicode (70/67) for anything else (e.g. Amharic).
 */
export function countSms(text: string): SmsCount {
  const basic = new Set(GSM7_BASIC);
  const extension = new Set(GSM7_EXTENSION);
  let encoding: SmsEncoding = 'GSM-7';
  let length = 0;
  for (const char of text) {
    if (basic.has(char)) length += 1;
    else if (extension.has(char)) length += 2;
    else {
      encoding = 'Unicode';
      break;
    }
  }
  if (encoding === 'Unicode') length = [...text].length;

  const perPart = encoding === 'GSM-7' ? 160 : 70;
  const multipartPerPart = encoding === 'GSM-7' ? 153 : 67;
  const parts = length === 0 ? 1 : length <= perPart ? 1 : Math.ceil(length / multipartPerPart);
  return { encoding, length, perPart: parts === 1 ? perPart : multipartPerPart, parts };
}

export type TemplatePreview = {
  text: string;
  count: SmsCount;
  unknown: string[];
};

/** Renders a body with sample data and counts the result. */
export function previewTemplate(
  body: string,
  sample: Partial<TemplateSample> = {},
): TemplatePreview {
  const merged = { ...SAMPLE_DATA, ...sample };
  const text = renderTemplate(body, merged);
  return { text, count: countSms(text), unknown: unknownVariables(body) };
}

/**
 * Clinical/financial terms that must not appear in a message (rule: no
 * diagnoses, treatments, doctor specialties, or prices). Checked
 * case-insensitively; both English and Amharic triggers are listed.
 */
export const SENSITIVE_TERMS = [
  'diagnosis',
  'diagnosed',
  'treatment',
  'prescription',
  'medicine',
  'medication',
  'surgery',
  'cataract',
  'glaucoma',
  'price',
  'birr',
  'etb',
  'discount',
  'ምርመራ',
  'ህክምና',
  'መድኃኒት',
  'ቀዶ',
  'ዋጋ',
  'ብር',
] as const;

/** Returns the sensitive terms found in a body (empty = clean). */
export function scanSensitiveContent(body: string): string[] {
  const lower = body.toLowerCase();
  const hits: string[] = [];
  for (const term of SENSITIVE_TERMS) {
    if (lower.includes(term.toLowerCase())) hits.push(term);
  }
  return hits;
}

export type MessageBodyCheck = {
  ok: boolean;
  /** i18n key under `messages.` describing the first problem, if any. */
  error?: 'messageRequired' | 'messageTooLong' | 'unknownVariable';
  unknown: string[];
  sensitive: string[];
};

/**
 * Validates a composer/library/picker message body: non-empty after trim,
 * within the campaign length cap, and using only known variables. Sensitive
 * terms are reported but do not block (they are surfaced as a warning).
 */
export function validateMessageBody(body: string): MessageBodyCheck {
  const trimmed = body.trim();
  const unknown = unknownVariables(body);
  const sensitive = scanSensitiveContent(body);
  if (!trimmed) return { ok: false, error: 'messageRequired', unknown, sensitive };
  if (trimmed.length > MAX_CAMPAIGN_MESSAGE) {
    return { ok: false, error: 'messageTooLong', unknown, sensitive };
  }
  if (unknown.length > 0) return { ok: false, error: 'unknownVariable', unknown, sensitive };
  return { ok: true, unknown, sensitive };
}
