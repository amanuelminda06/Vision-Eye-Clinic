'use server';

import { Prisma } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { SETTING_KEYS, settingsFormSchema, toSettingValues } from './schemas';
import { getSettingsMap } from './queries';

type WeekDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

function dayFrom(formData: FormData, day: WeekDay) {
  return {
    open: String(formData.get(`working_hours.${day}.open`) ?? ''),
    close: String(formData.get(`working_hours.${day}.close`) ?? ''),
    closed: formData.get(`working_hours.${day}.closed`) === 'true',
  };
}

export async function saveSettingsAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(['OWNER'], async (actor) => {
    const raw = {
      clinic_name: String(formData.get('clinic_name') ?? ''),
      currency: String(formData.get('currency') ?? ''),
      timezone: String(formData.get('timezone') ?? ''),
      working_hours: {
        mon: dayFrom(formData, 'mon'),
        tue: dayFrom(formData, 'tue'),
        wed: dayFrom(formData, 'wed'),
        thu: dayFrom(formData, 'thu'),
        fri: dayFrom(formData, 'fri'),
        sat: dayFrom(formData, 'sat'),
        sun: dayFrom(formData, 'sun'),
      },
      reminder_send_time: String(formData.get('reminder_send_time') ?? ''),
      reminder_send_by_offset_minutes: String(
        formData.get('reminder_send_by_offset_minutes') ?? '',
      ),
      followup_lead_days: String(formData.get('followup_lead_days') ?? ''),
      recall_months: String(formData.get('recall_months') ?? ''),
      review_link: String(formData.get('review_link') ?? ''),
      sms_mode: String(formData.get('sms_mode') ?? ''),
      reception_can_send_campaigns: formData.get('reception_can_send_campaigns') === 'true',
      campaign_max_recipients: String(formData.get('campaign_max_recipients') ?? ''),
      digest_enabled: formData.get('digest_enabled') === 'true',
      digest_email: String(formData.get('digest_email') ?? ''),
      default_language: String(formData.get('default_language') ?? ''),
      group_sms_allow_for_filtered: formData.get('group_sms_allow_for_filtered') === 'true',
      group_sms_max_recipients: String(formData.get('group_sms_max_recipients') ?? ''),
    };

    const values = validate(settingsFormSchema, raw);
    const map = toSettingValues(values);

    const before = await getSettingsMap();
    for (const key of SETTING_KEYS) {
      const value = map[key] as Prisma.InputJsonValue;
      await prisma.setting.upsert({
        where: { key },
        update: { value, updatedBy: actor.id },
        create: { key, value, updatedBy: actor.id },
      });
    }

    const old: Record<string, unknown> = {};
    for (const key of SETTING_KEYS) {
      if (key in before) old[key] = before[key];
    }

    await writeAudit({
      user: actor,
      entity: 'Setting',
      entityId: 'settings',
      action: 'SETTING_UPDATED',
      old,
      new: map,
    });

    return ok();
  });
}
