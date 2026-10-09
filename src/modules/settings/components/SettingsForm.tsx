'use client';

import { useActionState, useMemo } from 'react';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { saveSettingsAction } from '@/modules/settings/actions';
import { WEEKDAYS, type SettingsFormValues } from '@/modules/settings/schemas';
import type { ActionResult } from '@/lib/action';

type Props = {
  initial: SettingsFormValues;
};

const DAY_LABEL_KEYS = {
  mon: 'dayMon',
  tue: 'dayTue',
  wed: 'dayWed',
  thu: 'dayThu',
  fri: 'dayFri',
  sat: 'daySat',
  sun: 'daySun',
} as const;

export default function SettingsForm({ initial }: Props) {
  const t = useTranslations('settings');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const [state, formAction, pending] = useActionState<ActionResult | null, FormData>(
    saveSettingsAction,
    null,
  );
  const timezones = useMemo(
    () => (typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []),
    [],
  );

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  return (
    <form action={formAction} className="space-y-6">
      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
          {t('clinicName')}
        </h2>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="clinic_name">
              {t('clinicName')}
            </label>
            <input
              className="input-field"
              id="clinic_name"
              name="clinic_name"
              type="text"
              required
              defaultValue={initial.clinic_name}
              disabled={pending}
              aria-invalid={!!fieldError('clinic_name')}
            />
            {fieldError('clinic_name') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('clinic_name')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="currency">
              {t('currency')}
            </label>
            <input
              className="input-field"
              id="currency"
              name="currency"
              type="text"
              required
              defaultValue={initial.currency}
              disabled={pending}
              aria-invalid={!!fieldError('currency')}
            />
            {fieldError('currency') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('currency')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="timezone">
              {t('timezone')}
            </label>
            <select
              className="input-field"
              id="timezone"
              name="timezone"
              defaultValue={initial.timezone}
              disabled={pending}
              aria-invalid={!!fieldError('timezone')}
            >
              {timezones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </select>
            {fieldError('timezone') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('timezone')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="default_language">
              {t('defaultLanguage')}
            </label>
            <select
              className="input-field"
              id="default_language"
              name="default_language"
              defaultValue={initial.default_language}
              disabled={pending}
              aria-invalid={!!fieldError('default_language')}
            >
              <option value="en">English</option>
              <option value="am">አማርኛ</option>
            </select>
            {fieldError('default_language') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('default_language')}
              </p>
            ) : null}
          </div>
        </div>
      </section>

      <section className="card space-y-3 p-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
          {t('workingHours')}
        </h2>
        {WEEKDAYS.map((day) => {
          const value = initial.working_hours[day];
          const openError = fieldError(`working_hours.${day}.open`);
          const closeError = fieldError(`working_hours.${day}.close`);
          const dayError = fieldError(`working_hours.${day}.closed`);
          return (
            <div
              key={day}
              className="flex flex-wrap items-end gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
            >
              <div className="w-24 pb-2 text-sm font-medium text-slate-700">
                {t(DAY_LABEL_KEYS[day] as never)}
              </div>
              <div>
                <label className="label" htmlFor={`open-${day}`}>
                  {t('open')}
                </label>
                <input
                  className="input-field"
                  id={`open-${day}`}
                  name={`working_hours.${day}.open`}
                  type="time"
                  defaultValue={value.open}
                  disabled={pending}
                  aria-invalid={!!openError}
                />
                {openError ? (
                  <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                    {openError}
                  </p>
                ) : null}
              </div>
              <div>
                <label className="label" htmlFor={`close-${day}`}>
                  {t('close')}
                </label>
                <input
                  className="input-field"
                  id={`close-${day}`}
                  name={`working_hours.${day}.close`}
                  type="time"
                  defaultValue={value.close}
                  disabled={pending}
                  aria-invalid={!!closeError}
                />
                {closeError ? (
                  <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                    {closeError}
                  </p>
                ) : null}
              </div>
              <label className="flex items-center gap-2 pb-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  name={`working_hours.${day}.closed`}
                  value="true"
                  defaultChecked={value.closed}
                  disabled={pending}
                />
                {t('closed')}
              </label>
              {dayError ? (
                <p role="alert" className="w-full text-xs font-medium text-red-600">
                  {dayError}
                </p>
              ) : null}
            </div>
          );
        })}
      </section>

      <section className="card space-y-4 p-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">
          {t('reminderSendTime')}
        </h2>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="reminder_send_time">
              {t('reminderSendTime')}
            </label>
            <input
              className="input-field"
              id="reminder_send_time"
              name="reminder_send_time"
              type="time"
              defaultValue={initial.reminder_send_time}
              disabled={pending}
              aria-invalid={!!fieldError('reminder_send_time')}
            />
            {fieldError('reminder_send_time') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('reminder_send_time')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="reminder_send_by_offset_minutes">
              {t('reminderOffset')}
            </label>
            <input
              className="input-field"
              id="reminder_send_by_offset_minutes"
              name="reminder_send_by_offset_minutes"
              type="number"
              min={1}
              step={1}
              defaultValue={initial.reminder_send_by_offset_minutes}
              disabled={pending}
              aria-invalid={!!fieldError('reminder_send_by_offset_minutes')}
            />
            {fieldError('reminder_send_by_offset_minutes') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('reminder_send_by_offset_minutes')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="followup_lead_days">
              {t('followupLeadDays')}
            </label>
            <input
              className="input-field"
              id="followup_lead_days"
              name="followup_lead_days"
              type="number"
              min={1}
              step={1}
              defaultValue={initial.followup_lead_days}
              disabled={pending}
              aria-invalid={!!fieldError('followup_lead_days')}
            />
            {fieldError('followup_lead_days') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('followup_lead_days')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="recall_months">
              {t('recallMonths')}
            </label>
            <input
              className="input-field"
              id="recall_months"
              name="recall_months"
              type="number"
              min={1}
              step={1}
              defaultValue={initial.recall_months}
              disabled={pending}
              aria-invalid={!!fieldError('recall_months')}
            />
            {fieldError('recall_months') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('recall_months')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="review_link">
              {t('reviewLink')}
            </label>
            <input
              className="input-field"
              id="review_link"
              name="review_link"
              type="url"
              placeholder="https://…"
              defaultValue={initial.review_link}
              disabled={pending}
              aria-invalid={!!fieldError('review_link')}
            />
            {fieldError('review_link') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('review_link')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="sms_mode">
              {t('smsMode')}
            </label>
            <select
              className="input-field"
              id="sms_mode"
              name="sms_mode"
              defaultValue={initial.sms_mode}
              disabled={pending}
              aria-invalid={!!fieldError('sms_mode')}
            >
              <option value="MANUAL_LINK">{t('smsManualLink')}</option>
              <option value="GATEWAY">{t('smsGateway')}</option>
            </select>
            {fieldError('sms_mode') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('sms_mode')}
              </p>
            ) : null}
          </div>

          <div>
            <label className="label" htmlFor="campaign_max_recipients">
              {t('campaignMaxRecipients')}
            </label>
            <input
              className="input-field"
              id="campaign_max_recipients"
              name="campaign_max_recipients"
              type="number"
              min={1}
              max={300}
              step={1}
              defaultValue={initial.campaign_max_recipients}
              disabled={pending}
              aria-invalid={!!fieldError('campaign_max_recipients')}
            />
            {fieldError('campaign_max_recipients') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('campaign_max_recipients')}
              </p>
            ) : null}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            name="reception_can_send_campaigns"
            value="true"
            defaultChecked={initial.reception_can_send_campaigns}
            disabled={pending}
          />
          {t('receptionCampaigns')}
        </label>
        {fieldError('reception_can_send_campaigns') ? (
          <p role="alert" className="text-xs font-medium text-red-600">
            {fieldError('reception_can_send_campaigns')}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <label className="flex items-center gap-2 self-end text-sm text-slate-700">
            <input
              type="checkbox"
              name="digest_enabled"
              value="true"
              defaultChecked={initial.digest_enabled}
              disabled={pending}
            />
            {t('digestEnabled')}
          </label>
          <div>
            <label className="label" htmlFor="digest_email">
              {t('digestEmail')}
            </label>
            <input
              className="input-field"
              id="digest_email"
              name="digest_email"
              type="email"
              placeholder="owner@…"
              defaultValue={initial.digest_email}
              disabled={pending}
              aria-invalid={!!fieldError('digest_email')}
            />
            {fieldError('digest_email') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('digest_email')}
              </p>
            ) : null}
          </div>
        </div>
      </section>

      <ActionError error={failed ? state.error : undefined} />

      <div className="flex items-center gap-3">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : tc('save')}
        </button>
        {state?.ok ? (
          <p role="status" className="text-sm font-medium text-emerald-600">
            {tc('saved')}
          </p>
        ) : null}
      </div>
    </form>
  );
}
