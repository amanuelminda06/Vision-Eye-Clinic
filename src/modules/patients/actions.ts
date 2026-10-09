'use server';

import { Prisma } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { getSettingsMap } from '@/modules/settings/queries';
import { PATIENT_ROLES } from './roles';
import { patientFormSchema } from './schemas';
import { findPatientByPhone, findSimilarPatients } from './queries';
import { initialConsent, nextConsent, consentAuditEntry, type ConsentFields } from './consent';
import {
  MAX_IMPORT_BYTES,
  MAX_TAG_LENGTH,
  MAX_TAGS_PER_PATIENT,
  parseImport,
  type ImportRow,
  type ImportValues,
} from './import';
import { tagColorFor } from './tags';
import { cancelPendingForPatient, onConsentGranted } from '@/modules/messaging';

function isPrismaError(err: unknown, code: string): boolean {
  return err instanceof Prisma.PrismaClientKnownRequestError && err.code === code;
}

const snapshot = (patient: {
  fullName: string;
  phoneE164: string;
  sex: string | null;
  birthDate: Date | null;
  preferredLanguage: string;
  notesNonClinical: string | null;
  consentSms: boolean;
  consentDate: Date | null;
  consentRecordedBy: string | null;
  doNotMessage: boolean;
  doNotMessageReason: string | null;
  active: boolean;
}) => ({
  fullName: patient.fullName,
  phoneE164: patient.phoneE164,
  sex: patient.sex,
  birthDate: patient.birthDate,
  preferredLanguage: patient.preferredLanguage,
  notesNonClinical: patient.notesNonClinical,
  consentSms: patient.consentSms,
  consentDate: patient.consentDate,
  consentRecordedBy: patient.consentRecordedBy,
  doNotMessage: patient.doNotMessage,
  doNotMessageReason: patient.doNotMessageReason,
  active: patient.active,
});

function toDate(value: string | null): Date | null {
  return value ? new Date(`${value}T00:00:00Z`) : null;
}

function readPatientForm(formData: FormData) {
  return {
    fullName: String(formData.get('fullName') ?? ''),
    phone: String(formData.get('phone') ?? ''),
    sex: String(formData.get('sex') ?? ''),
    birthDate: String(formData.get('birthDate') ?? ''),
    preferredLanguage: String(formData.get('preferredLanguage') ?? ''),
    notesNonClinical: String(formData.get('notesNonClinical') ?? ''),
    consentSms: String(formData.get('consentSms') ?? 'false'),
  };
}

async function defaultLanguage(): Promise<'en' | 'am'> {
  const map = await getSettingsMap();
  return map.default_language === 'am' ? 'am' : 'en';
}

export type PatientFormSubmit =
  | { id: string }
  /** Very similar name + same birth date exists — the UI must confirm first. */
  | { needsConfirm: true; similar: Array<{ id: string; fullName: string }> };

export async function createPatientAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<PatientFormSubmit>> {
  return withAuth<PatientFormSubmit>(PATIENT_ROLES, async (actor) => {
    const values = validate(patientFormSchema, readPatientForm(formData));
    const confirmed = String(formData.get('confirm') ?? '') === 'true';

    const duplicate = await findPatientByPhone(values.phone);
    if (duplicate) return fail('phoneTaken', undefined, duplicate);

    if (!confirmed) {
      const similar = await findSimilarPatients({
        fullName: values.fullName,
        birthDate: values.birthDate,
      });
      if (similar.length > 0) return ok({ needsConfirm: true, similar });
    }

    const now = new Date();
    const consent = initialConsent(values.consentSms, now, actor.id);
    const language = values.preferredLanguage ?? (await defaultLanguage());

    let created;
    try {
      created = await prisma.patient.create({
        data: {
          fullName: values.fullName,
          phoneE164: values.phone,
          sex: values.sex,
          birthDate: toDate(values.birthDate),
          preferredLanguage: language,
          notesNonClinical: values.notesNonClinical,
          active: true,
          createdBy: actor.id,
          ...consent,
        },
      });
    } catch (err) {
      if (isPrismaError(err, 'P2002')) {
        const existing = await findPatientByPhone(values.phone);
        return fail('phoneTaken', undefined, existing ?? undefined);
      }
      throw err;
    }

    await writeAudit({
      user: actor,
      entity: 'Patient',
      entityId: created.id,
      action: 'PATIENT_CREATED',
      new: snapshot(created),
    });

    if (created.consentSms) await onConsentGranted(created.id);

    return ok({ id: created.id });
  });
}

export async function updatePatientAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<PatientFormSubmit>> {
  return withAuth<PatientFormSubmit>(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const values = validate(patientFormSchema, readPatientForm(formData));
    const confirmed = String(formData.get('confirm') ?? '') === 'true';

    const before = await prisma.patient.findUnique({ where: { id } });
    if (!before) return fail('unknownError');

    const duplicate = await findPatientByPhone(values.phone);
    if (duplicate && duplicate.id !== id) return fail('phoneTaken', undefined, duplicate);

    if (!confirmed) {
      const similar = await findSimilarPatients({
        fullName: values.fullName,
        birthDate: values.birthDate,
        excludeId: id,
      });
      if (similar.length > 0) return ok({ needsConfirm: true, similar });
    }

    const now = new Date();
    const consent: ConsentFields = nextConsent(
      {
        consentSms: before.consentSms,
        consentDate: before.consentDate,
        consentRecordedBy: before.consentRecordedBy,
      },
      values.consentSms,
      now,
      actor.id,
    );
    const language = values.preferredLanguage ?? before.preferredLanguage;

    const updated = await prisma.patient.update({
      where: { id },
      data: {
        fullName: values.fullName,
        phoneE164: values.phone,
        sex: values.sex,
        birthDate: toDate(values.birthDate),
        preferredLanguage: language,
        notesNonClinical: values.notesNonClinical,
        ...consent,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'Patient',
      entityId: id,
      action: 'PATIENT_UPDATED',
      old: snapshot(before),
      new: snapshot(updated),
    });

    const consentChange = consentAuditEntry(
      {
        consentSms: before.consentSms,
        consentDate: before.consentDate,
        consentRecordedBy: before.consentRecordedBy,
      },
      consent,
    );
    if (consentChange) {
      await writeAudit({
        user: actor,
        entity: 'Patient',
        entityId: id,
        action: 'PATIENT_CONSENT_CHANGED',
        old: consentChange.old,
        new: consentChange.new,
      });
    }

    if (!before.consentSms && updated.consentSms) await onConsentGranted(id);

    return ok({ id });
  });
}

export async function setPatientTagsAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('patientId') ?? '');
    const names = [
      ...new Set(
        String(formData.get('tags') ?? '')
          .split(',')
          .map((name) => name.trim().toLowerCase())
          .filter(Boolean),
      ),
    ];

    if (names.some((name) => name.length > MAX_TAG_LENGTH)) {
      return fail('validationError', { tags: 'tooLong' });
    }
    if (names.length > MAX_TAGS_PER_PATIENT) {
      return fail('validationError', { tags: 'tooMany' });
    }

    const patient = await prisma.patient.findUnique({
      where: { id },
      include: { tags: { include: { tag: true } } },
    });
    if (!patient) return fail('unknownError');

    const before = patient.tags.map((link) => link.tag.name);
    const changed = before.length !== names.length || before.some((name) => !names.includes(name));

    if (changed) {
      const tagIds: string[] = [];
      for (const name of names) {
        const tag = await prisma.tag.upsert({
          where: { name },
          update: {},
          create: { name, color: tagColorFor(name) },
        });
        tagIds.push(tag.id);
      }

      await prisma.$transaction([
        prisma.patientTag.deleteMany({ where: { patientId: id } }),
        ...tagIds.map((tagId) => prisma.patientTag.create({ data: { patientId: id, tagId } })),
      ]);

      await writeAudit({
        user: actor,
        entity: 'Patient',
        entityId: id,
        action: 'PATIENT_TAGS_CHANGED',
        old: { tags: [...before].sort() },
        new: { tags: [...names].sort() },
      });
    }

    return ok();
  });
}

export async function setPatientActiveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const active = String(formData.get('active') ?? '') === 'true';

    const before = await prisma.patient.findUnique({
      where: { id },
      select: { active: true },
    });
    if (!before) return fail('unknownError');
    if (before.active === active) return ok();

    await prisma.patient.update({ where: { id }, data: { active } });
    await writeAudit({
      user: actor,
      entity: 'Patient',
      entityId: id,
      action: 'PATIENT_STATUS_CHANGED',
      old: { active: before.active },
      new: { active },
    });
    return ok();
  });
}

export async function setDoNotMessageAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(PATIENT_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const stop = String(formData.get('stop') ?? '') === 'true';
    const reason = String(formData.get('reason') ?? '')
      .trim()
      .slice(0, 300);

    if (stop && !reason) return fail('validationError', { reason: 'required' });
    // Re-enabling messages after an opt-out is OWNER-only.
    if (!stop && actor.role !== 'OWNER') return fail('forbidden');

    const before = await prisma.patient.findUnique({
      where: { id },
      select: { doNotMessage: true, doNotMessageReason: true },
    });
    if (!before) return fail('unknownError');
    if (before.doNotMessage === stop && (stop ? before.doNotMessageReason === reason : true)) {
      return ok();
    }

    const doNotMessage = stop;
    await prisma.patient.update({
      where: { id },
      data: { doNotMessage, doNotMessageReason: stop ? reason : null },
    });
    if (stop) await cancelPendingForPatient(id);
    await writeAudit({
      user: actor,
      entity: 'Patient',
      entityId: id,
      action: 'PATIENT_DNM_CHANGED',
      old: { doNotMessage: before.doNotMessage, reason: before.doNotMessageReason },
      new: { doNotMessage, reason: stop ? reason : null },
    });
    return ok();
  });
}

export type ImportPreview = {
  step: 'preview';
  /** Echoed back so the confirm step re-validates the exact same file. */
  csv: string;
  missingColumns: string[];
  tooManyRows: boolean;
  rows: ImportRow[];
  validCount: number;
  errorCount: number;
};

export type ImportDone = { step: 'done'; imported: number; skipped: number };

async function existingPhoneSet(): Promise<Set<string>> {
  const rows = await prisma.patient.findMany({ select: { phoneE164: true } });
  return new Set(rows.map((row) => row.phoneE164));
}

export async function importPatientsAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<ImportPreview | ImportDone>> {
  return withAuth<ImportPreview | ImportDone>(PATIENT_ROLES, async (actor) => {
    const step = String(formData.get('step') ?? 'preview');
    const language = await defaultLanguage();
    const existingPhones = await existingPhoneSet();

    let text: string;
    if (step === 'preview') {
      const file = formData.get('file');
      if (!(file instanceof File) || file.size === 0) return fail('noFile');
      if (file.size > MAX_IMPORT_BYTES) return fail('fileTooLarge');
      text = await file.text();
    } else {
      text = String(formData.get('csv') ?? '');
      if (text.length > MAX_IMPORT_BYTES) return fail('fileTooLarge');
      if (String(formData.get('confirm') ?? '') !== 'true') return fail('confirmationRequired');
    }

    const result = parseImport(text, { defaultLanguage: language, existingPhones });

    if (result.missingColumns.length > 0) return fail('missingColumns');
    if (result.tooManyRows) return fail('tooManyRows');

    if (step === 'preview') {
      if (result.rows.length === 0) return fail('emptyFile');
      return ok<ImportPreview>({
        step: 'preview',
        csv: text,
        missingColumns: [],
        tooManyRows: false,
        rows: result.rows,
        validCount: result.validCount,
        errorCount: result.errorCount,
      });
    }

    // Import only fully valid rows — invalid ones stay in the error report.
    const now = new Date();
    let imported = 0;
    for (const row of result.rows) {
      const values: ImportValues | null = row.values;
      if (!values) continue;
      const consent = initialConsent(values.consentSms, now, actor.id);
      try {
        const created = await prisma.patient.create({
          data: {
            fullName: values.fullName,
            phoneE164: values.phoneE164,
            sex: values.sex,
            birthDate: toDate(values.birthDate),
            preferredLanguage: values.preferredLanguage,
            notesNonClinical: null,
            active: true,
            createdBy: actor.id,
            ...consent,
          },
        });
        const tagIds: string[] = [];
        for (const name of values.tags) {
          const tag = await prisma.tag.upsert({
            where: { name },
            update: {},
            create: { name, color: tagColorFor(name) },
          });
          tagIds.push(tag.id);
        }
        if (tagIds.length > 0) {
          await prisma.patientTag.createMany({
            data: tagIds.map((tagId) => ({ patientId: created.id, tagId })),
            skipDuplicates: true,
          });
        }
        await writeAudit({
          user: actor,
          entity: 'Patient',
          entityId: created.id,
          action: 'PATIENT_CREATED',
          new: snapshot(created),
        });
        imported++;
      } catch (err) {
        if (isPrismaError(err, 'P2002')) continue; // raced into the DB → counted as skipped
        throw err;
      }
    }

    return ok<ImportDone>({
      step: 'done',
      imported,
      skipped: result.rows.length - imported,
    });
  });
}
