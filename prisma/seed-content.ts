import type { PrismaClient } from '@prisma/client';
import { CONTENT_KEYS, DEFAULT_CONTENT } from '../src/modules/public-site/content';
import { REAL_SERVICES } from './seed-services';

/**
 * Public website seed: bilingual CMS-lite copy + which services are listed
 * publicly. Idempotent and non-destructive: existing SiteContent edits are kept
 * (upsert with an empty update), and service flags are only applied to the
 * clinic's real six services.
 */

const PUBLIC_DESCRIPTIONS: Record<string, { en: string; am: string }> = {
  'General Eye Care': {
    en: 'A complete examination of your eyes and vision, with clear next steps and honest advice.',
    am: 'የዓይንዎንና የእይታዎን አጠቃላይ ምርመራ፣ ግልጽ ቀጣይ እርምጃዎችና እውነተኛ ምክር ጋር።',
  },
  'Eye Examination for Glasses (Modern Equipment)': {
    en: 'A precise test using modern equipment to measure your prescription and find the right glasses.',
    am: 'ማዘዣዎን ለመለካትና ትክክለኛ መነጽር ለማግኘት በዘመናዊ መሳሪያዎች የሚደረግ ትክክለኛ ምርመራ።',
  },
  'Glasses Making and Sales': {
    en: 'We make and sell glasses to match your prescription, chosen to fit your face and your budget.',
    am: 'ማዘዣዎ የሚስማማ፣ ለፊትዎና ለበጀትዎ የሚመች መነጽር እንሰራለንና እንሸጣለን።',
  },
  'Special Eye Exam and Follow-up for Diabetes and Blood Pressure Patients': {
    en: 'Regular, careful eye checks and follow-up for patients managing diabetes or high blood pressure.',
    am: 'የስኳር ወይም የደም ግፊት ያለባቸው ታካሚዎች መደበኛና ጥንቃቄ የተሞላበት የዓይን ምርመራና ክትትል።',
  },
  "Children's Eye Exam and Treatment": {
    en: 'Gentle, friendly eye examinations and treatment for children so problems are caught early.',
    am: 'ለህጻናት ለስላሳና ወዳጃዊ የዓይን ምርመራና ህክምና፤ ችግሮች በቅርብ ጊዜ እንዲታወቁ ይረዳል።',
  },
  'Minor Eye Surgery': {
    en: 'Small, outpatient eye procedures performed in a clean, safe clinic setting.',
    am: 'ንጹህና ደህንነቱ የተጠበቀ ክሊኒካዊ አካባቢ የሚደረጉ ትናንሽ የዓይን ቀዶ ህክምናዎች።',
  },
};

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
  for (const service of REAL_SERVICES) {
    const description = PUBLIC_DESCRIPTIONS[service.nameEn] ?? { en: '', am: '' };
    const result = await prisma.service.updateMany({
      where: { nameEn: service.nameEn },
      data: {
        isPublic: true,
        publicDescriptionEn: description.en,
        publicDescriptionAm: description.am,
      },
    });
    publicServices += result.count;
  }

  return { keys: CONTENT_KEYS.length, publicServices };
}