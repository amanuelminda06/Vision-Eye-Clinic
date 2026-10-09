'use server';

import { withAuth, ok, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { writeAudit } from '@/lib/audit';
import { prisma } from '@/lib/prisma';
import { CONTENT_KEYS, resolveContent } from './content';
import { contentEditSchema } from './schemas';
import { CONTENT_ROLES } from './roles';

/**
 * Saves the bilingual public-website copy (CMS lite, OWNER only). Fields are
 * posted flat as `<key>_en` / `<key>_am`; `announcement_active` is a checkbox
 * mirrored into both locale columns. Audited with a per-key diff.
 */
export async function saveSiteContentAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(CONTENT_ROLES, async (actor) => {
    const raw: Record<string, string> = {};
    for (const [field, value] of formData.entries()) {
      if (typeof value === 'string') raw[field] = value;
    }
    const values = validate(contentEditSchema, raw);

    const before = await prisma.siteContent.findMany({
      select: { key: true, valueEn: true, valueAm: true },
    });
    const beforeMap = resolveContent(before);

    const next: Record<string, { en: string; am: string }> = {};
    for (const key of CONTENT_KEYS) {
      const en = (values[`${key}_en`] ?? '').trim();
      const am = (values[`${key}_am`] ?? '').trim();
      next[key] =
        key === 'announcement_active'
          ? { en: raw.announcement_active === 'true' ? 'true' : 'false', am: '' }
          : { en, am };
    }

    await prisma.$transaction(
      CONTENT_KEYS.map((key) =>
        prisma.siteContent.upsert({
          where: { key },
          update: { valueEn: next[key].en, valueAm: next[key].am, updatedBy: actor.id },
          create: { key, valueEn: next[key].en, valueAm: next[key].am, updatedBy: actor.id },
        }),
      ),
    );

    await writeAudit({
      user: actor,
      entity: 'SiteContent',
      entityId: 'all',
      action: 'SITE_CONTENT_UPDATED',
      old: beforeMap,
      new: next,
    });
    return ok();
  });
}
