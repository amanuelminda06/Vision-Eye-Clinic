'use server';

import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { PUBLIC_FEEDBACK_ACTOR } from './constants';
import { FEEDBACK_ROLES } from './roles';

export type PublicFeedbackResult = { ok: true; id: string } | { ok: false; error: string };

/**
 * Stores feedback submitted through the public contact form (no session).
 * New rows are always hidden from the website until the OWNER ticks
 * "show on website" in the CMS. The raw IP never reaches the row — only the
 * sha-256 hash, and the audit entry carries no IP at all.
 */
export async function createPublicFeedback(input: {
  name: string;
  email: string | null;
  rating: number | null;
  text: string;
  language: string;
  ipHash: string | null;
}): Promise<PublicFeedbackResult> {
  try {
    const created = await prisma.feedback.create({
      data: {
        name: input.name,
        email: input.email,
        rating: input.rating,
        text: input.text,
        language: input.language,
        ipHash: input.ipHash,
        showOnWebsite: false,
        createdBy: PUBLIC_FEEDBACK_ACTOR,
      },
      select: { id: true },
    });

    await writeAudit({
      user: null,
      entity: 'Feedback',
      entityId: created.id,
      action: 'FEEDBACK_SUBMITTED',
      // Public submissions never store the raw IP — only the sha-256 hash.
      ip: null,
      new: {
        source: 'WEBSITE',
        rating: input.rating,
        language: input.language,
        ipHash: input.ipHash,
      },
    });

    return { ok: true, id: created.id };
  } catch (err) {
    console.error('[feedback] failed to store public feedback:', err);
    return { ok: false, error: 'unknownError' };
  }
}

/**
 * Publishes/unpublishes one feedback entry as a website testimonial
 * (the CMS checkbox). OWNER only.
 */
export async function setFeedbackWebsiteAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(FEEDBACK_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');
    const showOnWebsite = String(formData.get('showOnWebsite') ?? '') === 'true';

    const before = await prisma.feedback.findUnique({
      where: { id },
      select: { id: true, showOnWebsite: true },
    });
    if (!before) return fail('unknownError');
    if (before.showOnWebsite === showOnWebsite) return ok();

    await prisma.feedback.update({ where: { id }, data: { showOnWebsite } });
    await writeAudit({
      user: actor,
      entity: 'Feedback',
      entityId: id,
      action: 'FEEDBACK_WEBSITE_CHANGED',
      old: { showOnWebsite: before.showOnWebsite },
      new: { showOnWebsite },
    });
    return ok();
  });
}

/** Removes a feedback entry (and its testimonial, if published). OWNER only. */
export async function deleteFeedbackAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(FEEDBACK_ROLES, async (actor) => {
    const id = String(formData.get('id') ?? '');

    const before = await prisma.feedback.findUnique({
      where: { id },
      select: { id: true, name: true, showOnWebsite: true },
    });
    if (!before) return fail('unknownError');

    await prisma.feedback.delete({ where: { id } });
    await writeAudit({
      user: actor,
      entity: 'Feedback',
      entityId: id,
      action: 'FEEDBACK_DELETED',
      old: { name: before.name, showOnWebsite: before.showOnWebsite },
    });
    return ok();
  });
}
