'use server';

import { withAuth, ok, type ActionResult } from '@/lib/action';
import { writeAudit } from '@/lib/audit';
import { clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { getEmailProvider } from '@/modules/messaging/email';
import { SALES_OWNER_ROLES } from '@/modules/sales/roles';
import { buildDailySummaryText, getDailySummary } from './daily';

export type SendDailySummaryResult = {
  to: string;
  mode: 'CONSOLE' | 'SMTP';
  dayKey: string;
};

/**
 * Sends the end-of-day sales summary email to the digest address (falling back
 * to the OWNER's own email) through the EmailProvider seam. OWNER only.
 */
export async function sendDailySummaryEmailAction(
  _prev: unknown,
  _formData: FormData,
): Promise<ActionResult<SendDailySummaryResult>> {
  void _prev;
  void _formData;
  return withAuth<SendDailySummaryResult>(SALES_OWNER_ROLES, async (user) => {
    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const dayKey = clinicDateKey(new Date(), tz);
    const summary = await getDailySummary(dayKey, tz);

    const recipient =
      typeof settings.digest_email === 'string' && settings.digest_email.trim()
        ? settings.digest_email.trim()
        : user.email;

    const provider = getEmailProvider();
    await provider.send({
      to: recipient,
      subject: `${dayKey} — Daily sales summary`,
      body: buildDailySummaryText(summary, {
        clinicName:
          typeof settings.clinic_name === 'string' && settings.clinic_name
            ? settings.clinic_name
            : 'Vision Eye Clinic',
        currency: typeof settings.currency === 'string' ? settings.currency : 'ETB',
        sentAt: new Date(),
      }),
    });

    await writeAudit({
      user,
      entity: 'sale',
      entityId: dayKey,
      action: 'DAILY_SUMMARY_EMAILED',
      new: { to: recipient, mode: provider.mode },
    });

    return ok<SendDailySummaryResult>({ to: recipient, mode: provider.mode, dayKey });
  });
}