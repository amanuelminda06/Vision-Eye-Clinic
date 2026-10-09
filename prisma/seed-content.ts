import type { PrismaClient } from '@prisma/client';
import { CONTENT_KEYS, DEFAULT_CONTENT } from '../src/modules/public-site/content';

/**
 * Public website seed: bilingual CMS-lite copy + which services are listed
 * publicly. Idempotent and non-destructive: existing SiteContent edits are kept
 * (upsert with an empty update), and service flags are only set once.
 */

const PUBLIC_SERVICES: Array<{ name: string; en: string; am: string }> = [
  {
    name: 'Eye Consultation',
    en: 'A thorough examination to understand your vision and eye health, with clear next steps.',
    am: 'የእይታዎንና የዓይን ጤናዎን ለመረዳት ጥልቅ ምርመራ፣ ከግልጽ ቀጣይ እርምጃዎች ጋር።',
  },
  {
    name: 'Refraction Test',
    en: 'Accurate measurement of your prescription for glasses or contact lenses.',
    am: 'ለብርጭቆ ወይም ለዓይን ሌንስ ትክክለኛ የማዘዣ መለኪያ።',
  },
  {
    name: 'OCT Scan',
    en: 'A quick, painless scan that images the retina and optic nerve in fine detail.',
    am: 'ሬቲናንና የዓይን ነርቭን በዝርዝር የሚያሳይ ፈጣንና ህመም የሌለው ስካን።',
  },
  {
    name: 'Glaucoma Screening',
    en: 'Early detection of glaucoma — a leading cause of preventable sight loss.',
    am: 'የግላኮማ ቀድሞ ማወቅ — ሊቀረች የሚችል የእይታ መጥፋት ዋነኛ መንስኤ።',
  },
  {
    name: 'Contact Lens Fitting',
    en: 'Assessment and fitting for comfortable, correctly sized contact lenses.',
    am: 'ምቹና ትክክለኛ መጠን ያላቸው የዓይን ሌንሶች ግምገማና ማስተካከል።',
  },
  {
    name: 'Prescription Glasses Fitting',
    en: 'Help choosing frames and lenses that fit your prescription and lifestyle.',
    am: 'ከማዘዣዎና ከአኗኗርዎ ጋር የሚስማሙ ማዕቀፎችንና ሌንሶችን ለመምረጥ እርዳታ።',
  },
];

export async function seedSiteContent(
  prisma: PrismaClient,
): Promise<{ keys: number; publicServices: number }> {
  for (const key of CONTENT_KEYS) {
    const value = DEFAULT_CONTENT[key];
    await prisma.siteContent.upsert({
      where: { key },
      update: {},
      create: { key, valueEn: value.en, valueAm: value.am },
    });
  }

  let publicServices = 0;
  for (const service of PUBLIC_SERVICES) {
    const result = await prisma.service.updateMany({
      where: { name: service.name },
      data: {
        isPublic: true,
        publicDescriptionEn: service.en,
        publicDescriptionAm: service.am,
      },
    });
    publicServices += result.count;
  }

  return { keys: CONTENT_KEYS.length, publicServices };
}
