import type { Prisma, PrismaClient } from '@prisma/client';
import { messageBodyHash } from '../src/modules/messaging/message-hash';

/**
 * Deterministic Message Library seeds: 10 reusable messages, each with an
 * English and an Amharic version (the duplicate guard keys on the same
 * normalised body hash per language, so English/Amharic never collide).
 * Idempotent via fixed ids + update.
 */
export async function seedMessageLibrary(prisma: PrismaClient, createdBy: string): Promise<number> {
  const pairs: {
    id: string;
    category: 'REMINDER' | 'GREETING' | 'ANNOUNCEMENT' | 'FOLLOWUP' | 'OTHER';
    title: string;
    bodyEn: string;
    bodyAm: string;
  }[] = [
    {
      id: 'lib-reminder-appointment',
      category: 'REMINDER',
      title: 'Appointment reminder',
      bodyEn:
        'Dear {name}, this is a reminder that your eye appointment at {clinic} ' +
        'is on {date} at {time}. Please arrive 15 minutes early. To reschedule contact the clinic.',
      bodyAm:
        'ውድ {name}፣ የአይን ቀጠሮዎ በ{clinic} በ{date} በ{time} መሆኑን ማስታወሻ ነው። ' +
        'እባክዎ ከቀጠሮው ከ15 ደቂቃ በፊት ይምጡ። ለማስተላለፍ ክሊኒኩን ያነጋግሩ።',
    },
    {
      id: 'lib-reminder-glasses',
      category: 'REMINDER',
      title: 'Glasses / documents reminder',
      bodyEn:
        'Dear {name}, please remember to bring your glasses and any previous prescriptions ' +
        'when you visit {clinic} on {date}.',
      bodyAm:
        'ውድ {name}፣ በ{date} ወደ {clinic} ሲመጡ ማንጻቂያ መነጽርዎንና የቀድሞ ሐኪም ትዕዛዝዎችን ' +
        'ማምጣትዎን ያስታውሱ።',
    },
    {
      id: 'lib-reminder-payment',
      category: 'REMINDER',
      title: 'Payment balance reminder',
      bodyEn:
        'Dear {name}, a balance remains on your account at {clinic}. ' +
        'Please settle it on your next visit or contact us to arrange payment.',
      bodyAm:
        'ውድ {name}፣ በ{clinic} ከሂሳብዎ ውስጥ ያልተከፈለ ቀሪ አለ። ' +
        'በሚቀጥለው ጉብኝትዎ ይክፈሉ ወይም ለክፍያ አማራጭ ያነጋግሩን።',
    },
    {
      id: 'lib-reminder-medication',
      category: 'REMINDER',
      title: 'Medication refill reminder',
      bodyEn:
        'Dear {name}, your eye medication is due for a refill. ' +
        'Visit {clinic} so we can review and renew your prescription.',
      bodyAm:
        'ውድ {name}፣ የአይን መድኃኒትዎ መታደስ ይገባዋል። ' +
        'የሐኪም ትዕዛዝዎን ለመገምገምና ለማደስ {clinic} ይጎብኙ።',
    },
    {
      id: 'lib-greeting-birthday',
      category: 'GREETING',
      title: 'Birthday greeting',
      bodyEn:
        'Happy birthday {name}! Wishing you a year of clear sight and great health. ' +
        'With love from the {clinic} family.',
      bodyAm:
        'መልካም ልደት {name}! በጥሩ እይታና ጤንነት የተሞላ ዓመት እንመኝልዎታለን። ' +
        'ከ{clinic} ቤተሰብ በፍቅር።',
    },
    {
      id: 'lib-greeting-newyear',
      category: 'GREETING',
      title: 'Ethiopian New Year',
      bodyEn:
        'Melkam Addis Amet {name}! May the new year bring you health and happiness. ' +
        'Best wishes from all of us at {clinic}.',
      bodyAm:
        'መልካም አዲስ ዓመት {name}! አዲሱ ዓመት ጤናና ደስታ ያስገኝልዎ። ' +
        'ከ{clinic} ሁላችንም ከልብ እንመኛለን።',
    },
    {
      id: 'lib-announcement-service',
      category: 'ANNOUNCEMENT',
      title: 'New service announcement',
      bodyEn:
        'Dear {name}, {clinic} now offers laser eye care. ' +
        'Call us to find out whether you qualify or to book an assessment.',
      bodyAm:
        'ውድ {name}፣ {clinic} አሁን በሌዘር የአይን ሕክምና ይሰጣል። ' +
        'ብቁ መሆንዎን ለማወቅ ወይም ለምርመራ ለመቀጠር ይደውሉልን።',
    },
    {
      id: 'lib-followup-postsurgery',
      category: 'FOLLOWUP',
      title: 'Post-surgery checkup',
      bodyEn:
        'Dear {name}, how are you recovering since your procedure? ' +
        'Please come for a quick post-op check at {clinic} on {date} {time}.',
      bodyAm:
        'ውድ {name}፣ ከሕክምናው በኋላ እንዴት እያገገሙ ነው? ' +
        'በ{clinic} በ{date} በ{time} ለቀዶ ምርመራ ይምጡ።',
    },
    {
      id: 'lib-followup-consult',
      category: 'FOLLOWUP',
      title: 'Follow-up visit',
      bodyEn:
        'Dear {name}, it is time for your follow-up visit at {clinic}. ' +
        'Contact us to choose a convenient day.',
      bodyAm:
        'ውድ {name}፣ በ{clinic} የክትትል ጉብኝት ጊዜው ደርሷል። ' +
        'ለምቾት ቀን ለመምረጥ ያነጋግሩን።',
    },
    {
      id: 'lib-other-feedback',
      category: 'OTHER',
      title: 'Share your feedback',
      bodyEn:
        'Dear {name}, we hope you are happy with your care at {clinic}. ' +
        'Your feedback helps us improve — reply to this message to share your experience.',
      bodyAm:
        'ውድ {name}፣ በ{clinic} በተሰጥዎት እንክብካቤ እንደሚደሰቱ ተስፋ እናደርጋለን። ' +
        'ግብረ መልስዎ እንድንሻሻል ይረዳናል — ልምድዎን ለማካፈል ለዚህ መልዕክት ይመልሱ።',
    },
  ];

  for (const item of pairs) {
    await prisma.messageLibraryItem.upsert({
      where: { id: item.id },
      update: {
        category: item.category,
        useCount: { increment: 0 },
      },
      create: {
        id: item.id,
        title: item.title,
        category: item.category,
        language: 'en',
        body: item.bodyEn,
        normalizedHash: messageBodyHash(item.bodyEn),
        createdBy,
      },
    });
    await prisma.messageLibraryItem.upsert({
      where: { id: `${item.id}-am` },
      update: {},
      create: {
        id: `${item.id}-am`,
        title: item.title,
        category: item.category,
        language: 'am',
        body: item.bodyAm,
        normalizedHash: messageBodyHash(item.bodyAm),
        createdBy,
      },
    });
  }
  return pairs.length * 2;
}

const AUDIENCES: { id: string; name: string; filter: Record<string, unknown> }[] = [
  {
    id: 'aud-seed-english',
    name: 'English-speaking patients with SMS consent',
    filter: { language: 'en' },
  },
  {
    id: 'aud-seed-last90',
    name: 'Visited in the last 90 days',
    filter: { presetRange: 'last90' },
  },
];

export async function seedAudiences(prisma: PrismaClient, createdBy: string): Promise<number> {
  for (const audience of AUDIENCES) {
    await prisma.audience.upsert({
      where: { id: audience.id },
      update: { name: audience.name, filterJson: audience.filter as Prisma.InputJsonValue },
      create: {
        id: audience.id,
        name: audience.name,
        filterJson: audience.filter as Prisma.InputJsonValue,
        createdBy,
      },
    });
  }
  return AUDIENCES.length;
}