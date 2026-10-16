'use server';

import { randomUUID } from 'node:crypto';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate, ValidationError } from '@/lib/errors';
import { storage } from '@/lib/storage';
import { toDecimalString } from '@/lib/money';
import { AuthorizationError, requireRole } from '@/lib/require-role';
import { SERVICES_ROLES } from './roles';
import { serviceFormSchema, serviceImageExtension } from './schemas';
import { listPriceHistory, type PriceHistoryRow } from './queries';

const IMAGE_URL_PREFIX = '/api/uploads/';

function readServiceForm(formData: FormData) {
  const image = formData.get('image');
  return {
    name: String(formData.get('name') ?? ''),
    nameEn: String(formData.get('nameEn') ?? ''),
    nameAm: String(formData.get('nameAm') ?? ''),
    category: String(formData.get('category') ?? ''),
    currentPrice: String(formData.get('currentPrice') ?? ''),
    isPublic: String(formData.get('isPublic') ?? ''),
    publicDescriptionEn: String(formData.get('publicDescriptionEn') ?? ''),
    publicDescriptionAm: String(formData.get('publicDescriptionAm') ?? ''),
    receiptLabelEn: String(formData.get('receiptLabelEn') ?? ''),
    receiptLabelAm: String(formData.get('receiptLabelAm') ?? ''),
    displayOrder: String(formData.get('displayOrder') ?? ''),
    imageUrl: String(formData.get('imageUrl') ?? ''),
    image: image instanceof File && image.size > 0 ? image : undefined,
  };
}

async function storeServiceImage(file: File): Promise<string> {
  const ext = serviceImageExtension(file);
  if (!ext) throw new ValidationError({ image: 'imageInvalidType' });
  const key = `service-${randomUUID()}.${ext}`;
  const buffer = Buffer.from(await file.arrayBuffer());
  await storage.put(key, buffer);
  return `${IMAGE_URL_PREFIX}${key}`;
}

function storageKeyFromImageUrl(url: string | null): string | null {
  if (!url || !url.startsWith(IMAGE_URL_PREFIX)) return null;
  try {
    return decodeURIComponent(url.slice(IMAGE_URL_PREFIX.length));
  } catch {
    return null;
  }
}

export async function createServiceAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(SERVICES_ROLES, async (actor) => {
    const values = validate(serviceFormSchema, readServiceForm(formData));
    const { image, ...fields } = values;
    const imageUrl = image ? await storeServiceImage(image) : fields.imageUrl ?? null;

    const created = await prisma.service.create({
      data: {
        name: values.nameEn,
        nameEn: values.nameEn,
        nameAm: values.nameAm || null,
        category: values.category,
        currentPrice: values.currentPrice,
        active: true,
        isPublic: values.isPublic,
        publicDescriptionEn: values.publicDescriptionEn,
        publicDescriptionAm: values.publicDescriptionAm,
        receiptLabelEn: values.receiptLabelEn,
        receiptLabelAm: values.receiptLabelAm,
        displayOrder: values.displayOrder,
        imageUrl,
      },
    });

    await writeAudit({
      user: actor,
      entity: 'Service',
      entityId: created.id,
      action: 'SERVICE_CREATED',
      new: {
        name: created.name,
        nameAm: created.nameAm,
        category: created.category,
        currentPrice: toDecimalString(created.currentPrice),
        active: created.active,
        isPublic: created.isPublic,
      },
    });

    return ok({ id: created.id });
  });
}

export async function updateServiceAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth<{ id: string }>(SERVICES_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const values = validate(serviceFormSchema, readServiceForm(formData));
    const { image, ...fields } = values;

    const before = await prisma.service.findUnique({ where: { id } });
    if (!before) return fail('unknownError');

    const beforePrice = toDecimalString(before.currentPrice);

    const imageUrl = image
      ? await storeServiceImage(image)
      : fields.imageUrl ?? before.imageUrl ?? null;

    const updated = await prisma.service.update({
      where: { id },
      data: {
        name: values.nameEn,
        nameEn: values.nameEn,
        nameAm: values.nameAm || null,
        category: values.category,
        currentPrice: values.currentPrice,
        isPublic: values.isPublic,
        publicDescriptionEn: values.publicDescriptionEn,
        publicDescriptionAm: values.publicDescriptionAm,
        receiptLabelEn: values.receiptLabelEn,
        receiptLabelAm: values.receiptLabelAm,
        displayOrder: values.displayOrder,
        imageUrl,
      },
    });

    if (image && before.imageUrl && before.imageUrl !== imageUrl) {
      const oldKey = storageKeyFromImageUrl(before.imageUrl);
      if (oldKey) {
        await storage.remove(oldKey).catch(() => undefined);
      }
    }

    await writeAudit({
      user: actor,
      entity: 'Service',
      entityId: id,
      action: 'SERVICE_UPDATED',
      old: {
        name: before.name,
        nameAm: before.nameAm,
        category: before.category,
        currentPrice: beforePrice,
        isPublic: before.isPublic,
      },
      new: {
        name: updated.name,
        nameAm: updated.nameAm,
        category: updated.category,
        currentPrice: toDecimalString(updated.currentPrice),
        isPublic: updated.isPublic,
      },
    });

    // Price changes are recorded historically AND audited on their own
    // (rule: changing a price never touches existing sales — they keep their
    // own line snapshots).
    const newPrice = toDecimalString(updated.currentPrice);
    if (beforePrice !== newPrice) {
      await prisma.servicePriceHistory.create({
        data: { serviceId: id, oldPrice: beforePrice, newPrice, changedBy: actor.id },
      });
      await writeAudit({
        user: actor,
        entity: 'Service',
        entityId: id,
        action: 'SERVICE_PRICE_CHANGED',
        old: { currentPrice: beforePrice },
        new: { currentPrice: newPrice },
      });
    }

    return ok({ id });
  });
}

export async function setServiceActiveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(SERVICES_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const active = String(formData.get('active') ?? '') === 'true';

    const before = await prisma.service.findUnique({
      where: { id },
      select: { active: true },
    });
    if (!before) return fail('unknownError');
    if (before.active === active) return ok();

    await prisma.service.update({ where: { id }, data: { active } });
    await writeAudit({
      user: actor,
      entity: 'Service',
      entityId: id,
      action: 'SERVICE_STATUS_CHANGED',
      old: { active: before.active },
      new: { active },
    });
    return ok();
  });
}

/** Price history for the modal — OWNER only. */
export async function getPriceHistoryAction(
  serviceId: string,
): Promise<ActionResult<PriceHistoryRow[]>> {
  try {
    await requireRole(SERVICES_ROLES);
  } catch (err) {
    if (err instanceof AuthorizationError) return fail('forbidden');
    throw err;
  }
  const rows = await listPriceHistory(String(serviceId).slice(0, 64));
  return ok(rows);
}
