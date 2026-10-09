'use server';

import { randomUUID } from 'node:crypto';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate, ValidationError } from '@/lib/errors';
import { storage } from '@/lib/storage';
import { photoExtension, staffFormSchema, type StaffFormValues } from './schemas';

const PHOTO_URL_PREFIX = '/api/uploads/';

function readForm(formData: FormData): unknown {
  const photo = formData.get('photo');
  return {
    fullName: String(formData.get('fullName') ?? ''),
    jobTitle: String(formData.get('jobTitle') ?? ''),
    isDoctor: String(formData.get('isDoctor') ?? 'false'),
    specialty: String(formData.get('specialty') ?? ''),
    phone: String(formData.get('phone') ?? ''),
    showOnWebsite: String(formData.get('showOnWebsite') ?? 'false'),
    photo: photo instanceof File && photo.size > 0 ? photo : undefined,
  };
}

async function storePhoto(file: File): Promise<string> {
  const ext = photoExtension(file);
  if (!ext) throw new ValidationError({ photo: 'photoInvalidType' });
  const key = `staff-${randomUUID()}.${ext}`;
  const buffer = Buffer.from(await file.arrayBuffer());
  await storage.put(key, buffer);
  return `${PHOTO_URL_PREFIX}${key}`;
}

function storageKeyFromUrl(url: string | null): string | null {
  if (!url || !url.startsWith(PHOTO_URL_PREFIX)) return null;
  try {
    return decodeURIComponent(url.slice(PHOTO_URL_PREFIX.length));
  } catch {
    return null;
  }
}

function auditFields(values: Omit<StaffFormValues, 'photo'>, photoUrl: string | null) {
  return {
    fullName: values.fullName,
    jobTitle: values.jobTitle,
    isDoctor: values.isDoctor,
    specialty: values.specialty,
    phone: values.phone,
    showOnWebsite: values.showOnWebsite,
    photoUrl,
  };
}

export async function createStaffAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth(['OWNER'], async (actor) => {
    const values = validate(staffFormSchema, readForm(formData));
    const { photo, ...fields } = values;
    const photoUrl = photo ? await storePhoto(photo) : null;

    const created = await prisma.staff.create({
      data: { ...auditFields(fields, photoUrl), active: true },
    });

    await writeAudit({
      user: actor,
      entity: 'Staff',
      entityId: created.id,
      action: 'STAFF_CREATED',
      new: { ...auditFields(fields, photoUrl), active: true },
    });

    return ok({ id: created.id });
  });
}

export async function updateStaffAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth(['OWNER'], async (actor) => {
    const id = String(formData.get('id') ?? '');
    const values = validate(staffFormSchema, readForm(formData));
    const { photo, ...fields } = values;

    const before = await prisma.staff.findUnique({ where: { id } });
    if (!before) return fail('unknownError');

    const photoUrl = photo ? await storePhoto(photo) : before.photoUrl;
    const updated = await prisma.staff.update({
      where: { id },
      data: auditFields(fields, photoUrl),
    });

    if (photo && before.photoUrl && before.photoUrl !== photoUrl) {
      const oldKey = storageKeyFromUrl(before.photoUrl);
      if (oldKey) {
        await storage.remove(oldKey).catch(() => undefined);
      }
    }

    await writeAudit({
      user: actor,
      entity: 'Staff',
      entityId: id,
      action: 'STAFF_UPDATED',
      old: auditFields(
        {
          fullName: before.fullName,
          jobTitle: before.jobTitle,
          isDoctor: before.isDoctor,
          specialty: before.specialty,
          phone: before.phone,
          showOnWebsite: before.showOnWebsite,
        },
        before.photoUrl,
      ),
      new: auditFields(updated, updated.photoUrl),
    });

    return ok({ id });
  });
}

export async function setStaffActiveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(['OWNER'], async (actor) => {
    const id = String(formData.get('id') ?? '');
    const active = String(formData.get('active') ?? '') === 'true';

    const before = await prisma.staff.findUnique({
      where: { id },
      select: { active: true },
    });
    if (!before) return fail('unknownError');
    if (before.active === active) return ok();

    await prisma.staff.update({ where: { id }, data: { active } });
    await writeAudit({
      user: actor,
      entity: 'Staff',
      entityId: id,
      action: 'STAFF_STATUS_CHANGED',
      old: { active: before.active },
      new: { active },
    });
    return ok();
  });
}
