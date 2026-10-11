import { describe, expect, it } from 'vitest';
import { settingsFormSchema, SETTING_KEYS, toSettingValues } from './schemas';

function validRaw() {
  return {
    clinic_name: 'Vision Eye Clinic',
    currency: 'ETB',
    timezone: 'Africa/Addis_Ababa',
    working_hours: {
      mon: { open: '08:00', close: '17:00', closed: false },
      tue: { open: '08:00', close: '17:00', closed: false },
      wed: { open: '08:00', close: '17:00', closed: false },
      thu: { open: '08:00', close: '17:00', closed: false },
      fri: { open: '08:00', close: '17:00', closed: false },
      sat: { open: '08:00', close: '12:00', closed: false },
      sun: { open: '08:00', close: '12:00', closed: true },
    },
    reminder_send_time: '14:00',
    reminder_send_by_offset_minutes: '60',
    followup_lead_days: '3',
    recall_months: '12',
    review_link: '',
    sms_mode: 'MANUAL_LINK',
    reception_can_send_campaigns: false,
    campaign_max_recipients: '300',
    digest_enabled: false,
    digest_email: '',
    default_language: 'en',
    group_sms_allow_for_filtered: false,
    group_sms_max_recipients: '15',
  };
}

function issueMessage(result: ReturnType<typeof settingsFormSchema.safeParse>): string | undefined {
  return result.success ? undefined : result.error.issues[0]?.message;
}

describe('settingsFormSchema', () => {
  it('accepts a valid payload and coerces numbers', () => {
    const result = settingsFormSchema.safeParse(validRaw());
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.reminder_send_by_offset_minutes).toBe(60);
      expect(result.data.followup_lead_days).toBe(3);
      expect(result.data.recall_months).toBe(12);
      expect(result.data.working_hours.sun.closed).toBe(true);
    }
  });

  it('rejects malformed times with the invalidTime key', () => {
    const raw = validRaw();
    raw.reminder_send_time = '25:00';
    expect(issueMessage(settingsFormSchema.safeParse(raw))).toBe('invalidTime');
  });

  it('rejects an unknown timezone with invalidTimezone', () => {
    const raw = validRaw();
    raw.timezone = 'Mars/Olympus';
    expect(issueMessage(settingsFormSchema.safeParse(raw))).toBe('invalidTimezone');
  });

  it('rejects a closing time at or before the opening time', () => {
    const raw = validRaw();
    raw.working_hours.mon = { open: '09:00', close: '09:00', closed: false };
    expect(issueMessage(settingsFormSchema.safeParse(raw))).toBe('endTimeAfterStart');

    const reversed = validRaw();
    reversed.working_hours.tue = { open: '17:00', close: '08:00', closed: false };
    expect(issueMessage(settingsFormSchema.safeParse(reversed))).toBe('endTimeAfterStart');
  });

  it('still accepts a closed day with any valid times', () => {
    const raw = validRaw();
    raw.working_hours.sun = { open: '09:00', close: '09:30', closed: true };
    expect(settingsFormSchema.safeParse(raw).success).toBe(true);
  });

  it('rejects zero and non-numeric integers with the right keys', () => {
    const zero = validRaw();
    zero.followup_lead_days = '0';
    expect(issueMessage(settingsFormSchema.safeParse(zero))).toBe('positive');

    const notANumber = validRaw();
    notANumber.recall_months = 'abc';
    expect(issueMessage(settingsFormSchema.safeParse(notANumber))).toBe('invalid');
  });

  it('rejects malformed review links and accepts empty or https', () => {
    const empty = validRaw();
    expect(settingsFormSchema.safeParse(empty).success).toBe(true);

    const valid = validRaw();
    valid.review_link = 'https://example.com/review';
    expect(settingsFormSchema.safeParse(valid).success).toBe(true);

    const bad = validRaw();
    bad.review_link = 'ftp://example.com';
    expect(issueMessage(settingsFormSchema.safeParse(bad))).toBe('invalidUrl');
  });

  it('rejects unknown sms_mode and default_language values', () => {
    const sms = validRaw();
    sms.sms_mode = 'SMOKE_SIGNAL';
    expect(issueMessage(settingsFormSchema.safeParse(sms))).toBe('invalid');

    const lang = validRaw();
    lang.default_language = 'fr';
    expect(issueMessage(settingsFormSchema.safeParse(lang))).toBe('invalid');
  });

  it('rejects an empty clinic name', () => {
    const raw = validRaw();
    raw.clinic_name = '   ';
    expect(issueMessage(settingsFormSchema.safeParse(raw))).toBe('required');
  });
});

describe('toSettingValues', () => {
  it('produces exactly the seeded storage keys with JSON-safe values', () => {
    const result = settingsFormSchema.safeParse(validRaw());
    expect(result.success).toBe(true);
    if (!result.success) return;

    const map = toSettingValues(result.data);
    expect(Object.keys(map).sort()).toEqual([...SETTING_KEYS].sort());
    expect(map.clinic_name).toBe('Vision Eye Clinic');
    expect(map.followup_lead_days).toBe(3);
    expect(map.reception_can_send_campaigns).toBe(false);
    expect(map.working_hours).toBeTypeOf('object');
    expect(() => JSON.stringify(map)).not.toThrow();
  });
});
