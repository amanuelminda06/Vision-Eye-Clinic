import type { PrismaClient } from '@prisma/client';

/**
 * Seeded SMS templates (en + am). Amharic bodies are first-pass drafts marked
 * "needs native review" in the template editor — the clinic should refine them
 * before going live. Variables are limited to `TEMPLATE_VARIABLES`.
 */

type Language = 'en' | 'am';

const TEMPLATES: Record<string, Record<Language, string>> = {
  APPT_REMINDER: {
    en: 'Hello {name}, this is a reminder of your appointment at {clinic} on {date} at {time} with {doctor}. Reply or call if you need to change it.',
    am: 'ሰላም {name}፣ በ{clinic} ቀን {date} ሰዓት {time} ከ{doctor} ጋር ያለዎትን ቀጠሮ እናስታውስዎታለን። መቀየር ከፈለጉ ይደውሉ።',
  },
  APPT_CONFIRMATION: {
    en: 'Hello {name}, your appointment at {clinic} on {date} at {time} with {doctor} is confirmed. See you then.',
    am: 'ሰላም {name}፣ በ{clinic} ቀን {date} ሰዓት {time} ከ{doctor} ጋር ያለዎት ቀጠሮ ተረጋግጧል። እንገናኝ።',
  },
  APPT_SAME_DAY: {
    en: 'Good morning {name}, you have an appointment today at {clinic} at {time} with {doctor}. Please arrive 10 minutes early.',
    am: 'እንደምን አደሩ {name}፣ ዛሬ በ{clinic} ሰዓት {time} ከ{doctor} ጋር ቀጠሮ አለዎት። 10 ደቂቃ ቀድመው ይምጡ።',
  },
  NO_SHOW: {
    en: 'Hello {name}, we missed you at {clinic} today. Please call us to book a new appointment.',
    am: 'ሰላም {name}፣ ዛሬ በ{clinic} አልገኙም። አዲስ ቀጠሮ ለመያዝ ይደውሉልን።',
  },
  SCHEDULE_CHANGE: {
    en: 'Hello {name}, your appointment at {clinic} has changed. The new time is {date} at {time} with {doctor}. Sorry for the inconvenience.',
    am: 'ሰላም {name}፣ በ{clinic} ያለዎት ቀጠሮ ተቀይሯል። አዲሱ ጊዜ {date} ሰዓት {time} ከ{doctor} ጋር ነው። ይቅርታ።',
  },
  FOLLOWUP_DUE: {
    en: 'Hello {name}, it is time for your follow-up visit at {clinic}. Please call us to schedule it.',
    am: 'ሰላም {name}፣ በ{clinic} የክትትል ጉብኝትዎ ወቅት ደርሷል። ለመያዝ ይደውሉልን።',
  },
  RECALL: {
    en: 'Hello {name}, it has been a while since your last visit to {clinic}. We would be glad to see you again — call us to book.',
    am: 'ሰላም {name}፣ ከመጨረሻ ጉብኝትዎ በ{clinic} ጊዜ ተቀይሯል። እንደገና ለማግኘት ደስ ይለናል — ለመያዝ ይደውሉ።',
  },
  GLASSES_READY: {
    en: 'Hello {name}, your glasses are ready at {clinic}. You can collect them during working hours.',
    am: 'ሰላም {name}፣ መነጽርዎ በ{clinic} ተዘጋጅቷል። በስራ ሰዓት መውሰድ ይችላሉ።',
  },
  THANK_YOU: {
    en: 'Thank you for visiting {clinic}, {name}. We hope you feel better.\nPlease leave us a review: {review_link}',
    am: 'ስለ ጉብኝትዎ እናመሰግናለን {name}። እንዲሻሻልልዎ ተስፋ እናደርጋለን።\nአስተያየትዎን ይስጡን: {review_link}',
  },
  BIRTHDAY: {
    en: 'Happy birthday, {name}! From all of us at {clinic}.',
    am: 'መልካም ልደት {name}! ከ{clinic} ሰራተኞች።',
  },
  WELCOME: {
    en: 'Welcome to {clinic}, {name}. We will send appointment reminders to this number. Reply STOP to stop messages.',
    am: 'ወደ {clinic} እንኳን በደህና መጡ {name}። የቀጠሮ ማስታወሻዎችን ወደዚህ ቁጥር እንልካለን። ለማቆም STOP ብለው ይላኩ።',
  },
  CLOSURE_NOTICE: {
    en: 'Hello {name}, {clinic} will be closed and will reopen on {reopen_date}. We apologize for any inconvenience.',
    am: 'ሰላም {name}፣ {clinic} ይዘጋል እና በ{reopen_date} ይከፈታል። ይቅርታ።',
  },
  CUSTOM: {
    en: 'Hello {name}, this is a message from {clinic}.',
    am: 'ሰላም {name}፣ ከ{clinic} የተላከ መልእክት ነው።',
  },
};

export async function seedMessageTemplates(prisma: PrismaClient): Promise<number> {
  let count = 0;
  for (const [key, bodies] of Object.entries(TEMPLATES)) {
    for (const language of ['en', 'am'] as const) {
      await prisma.messageTemplate.upsert({
        where: { key_language: { key: key as never, language } },
        update: {},
        create: { key: key as never, language, body: bodies[language], active: true },
      });
      count += 1;
    }
  }
  return count;
}
