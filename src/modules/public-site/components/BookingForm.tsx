'use client';

import { useState } from 'react';
import { useLocale, useTranslations } from 'next-intl';
import type { PublicService } from '../projection';

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY;

const ERROR_CODES = ['required', 'tooLong', 'invalidDate', 'invalidTimeWindow', 'consent'] as const;
type ErrorCode = (typeof ERROR_CODES)[number];

function errorLabel(code: string | undefined): ErrorCode | null {
  return code && (ERROR_CODES as readonly string[]).includes(code) ? (code as ErrorCode) : null;
}

type Props = {
  services: PublicService[];
  windows: string[];
  /** Clinic-local "today" (YYYY-MM-DD) so the date picker cannot go backwards. */
  minDate: string;
};

type Status = 'idle' | 'submitting' | 'success' | 'error';

export default function BookingForm({ services, windows, minDate }: Props) {
  const t = useTranslations('site');
  const locale = useLocale();
  const [status, setStatus] = useState<Status>('idle');
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);

    const turnstileToken =
      SITE_KEY && typeof window !== 'undefined' && window.turnstile
        ? (window.turnstile.getResponse() ?? '')
        : undefined;

    const payload = {
      name: String(data.get('name') ?? ''),
      phone: String(data.get('phone') ?? ''),
      preferredDate: String(data.get('preferredDate') ?? ''),
      preferredTimeWindow: String(data.get('preferredTimeWindow') ?? 'any'),
      serviceInterest: String(data.get('serviceInterest') ?? '') || undefined,
      message: String(data.get('message') ?? '') || undefined,
      language: locale,
      consent: data.get('consent') === 'on',
      website: String(data.get('website') ?? ''),
      turnstileToken,
    };

    setStatus('submitting');
    setError(null);
    setFields({});

    try {
      const response = await fetch('/api/public/requests', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const json = (await response.json()) as {
        ok?: boolean;
        error?: string;
        fields?: Record<string, string>;
      };

      if (response.status === 429) {
        setStatus('error');
        setError(t('book.errorRateLimited'));
        return;
      }
      if (response.ok && json.ok) {
        setStatus('success');
        form.reset();
        window.turnstile?.reset();
        return;
      }

      setStatus('error');
      setFields(json.fields ?? {});
      if (json.error !== 'validationError') setError(t('book.errorGeneric'));
      else setError(t('book.errorValidation'));
    } catch {
      setStatus('error');
      setError(t('book.errorGeneric'));
    }
  }

  if (status === 'success') {
    return (
      <div
        role="status"
        className="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center"
      >
        <p className="text-lg font-semibold text-emerald-900">{t('book.successTitle')}</p>
        <p className="mt-1 text-sm text-emerald-800">{t('book.successBody')}</p>
        <button
          type="button"
          onClick={() => setStatus('idle')}
          className="mt-4 rounded-lg border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100"
        >
          {t('book.successAgain')}
        </button>
      </div>
    );
  }

  const fieldError = (name: string) => {
    const code = errorLabel(fields[name]);
    if (code) return t(`book.errors.${code}` as never);
    if (fields[name] === 'invalidLanguage' || fields[name] === 'spam') {
      return t('book.errorValidation');
    }
    return null;
  };

  const describedBy = (name: string) => (fieldError(name) ? `${name}-error` : undefined);

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      {error ? (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      ) : null}

      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label={t('book.name')}
          htmlFor="name"
          required
          error={fieldError('name')}
          errorId="name-error"
        >
          <input
            id="name"
            name="name"
            required
            maxLength={120}
            autoComplete="name"
            placeholder={t('book.namePlaceholder')}
            aria-describedby={describedBy('name')}
            aria-invalid={fieldError('name') ? true : undefined}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
          />
        </Field>

        <Field
          label={t('book.phone')}
          htmlFor="phone"
          required
          hint={t('book.phoneHint')}
          error={fieldError('phone')}
          errorId="phone-error"
        >
          <input
            id="phone"
            name="phone"
            type="tel"
            required
            inputMode="tel"
            autoComplete="tel"
            placeholder={t('book.phonePlaceholder')}
            aria-describedby={describedBy('phone')}
            aria-invalid={fieldError('phone') ? true : undefined}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
          />
        </Field>

        <Field
          label={t('book.preferredDate')}
          htmlFor="preferredDate"
          required
          error={fieldError('preferredDate')}
          errorId="preferredDate-error"
        >
          <input
            id="preferredDate"
            name="preferredDate"
            type="date"
            required
            min={minDate}
            aria-describedby={describedBy('preferredDate')}
            aria-invalid={fieldError('preferredDate') ? true : undefined}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
          />
        </Field>

        <Field
          label={t('book.timeWindow')}
          htmlFor="preferredTimeWindow"
          error={fieldError('preferredTimeWindow')}
          errorId="preferredTimeWindow-error"
        >
          <select
            id="preferredTimeWindow"
            name="preferredTimeWindow"
            defaultValue="any"
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
          >
            {windows.map((window) => (
              <option key={window} value={window}>
                {t(`book.window${window[0].toUpperCase()}${window.slice(1)}` as never)}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <Field label={t('book.serviceInterest')} htmlFor="serviceInterest">
        <select
          id="serviceInterest"
          name="serviceInterest"
          defaultValue=""
          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
        >
          <option value="">{t('book.serviceNone')}</option>
          {services.map((service) => (
            <option key={service.id} value={service.name}>
              {service.name}
            </option>
          ))}
        </select>
      </Field>

      <Field
        label={t('book.message')}
        htmlFor="message"
        hint={t('book.messageHint', { max: 500 })}
        optionalLabel={t('book.messageOptional')}
        error={fieldError('message')}
        errorId="message-error"
      >
        <textarea
          id="message"
          name="message"
          rows={3}
          maxLength={500}
          placeholder={t('book.messagePlaceholder')}
          aria-describedby={describedBy('message')}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
        />
      </Field>

      {/* Honeypot — hidden from humans and assistive tech. */}
      <div className="hidden" aria-hidden="true">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
      </div>

      {SITE_KEY ? (
        <div className="cf-turnstile" data-sitekey={SITE_KEY} data-theme="light" />
      ) : null}

      <label className="flex items-start gap-3 text-sm text-slate-700">
        <input
          type="checkbox"
          name="consent"
          required
          aria-describedby={describedBy('consent')}
          className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
        />
        <span>
          {t('book.consent')}
          {fieldError('consent') ? (
            <span id="consent-error" className="mt-1 block text-red-600">
              {fieldError('consent')}
            </span>
          ) : null}
        </span>
      </label>

      <div className="flex items-center gap-4">
        <button
          type="submit"
          disabled={status === 'submitting'}
          className="rounded-lg bg-teal-700 px-6 py-3 text-base font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
        >
          {status === 'submitting' ? t('book.submitting') : t('book.submit')}
        </button>
        <p className="text-xs text-slate-500">{t('book.requiredNote')}</p>
      </div>
    </form>
  );
}

function Field({
  label,
  htmlFor,
  required,
  hint,
  optionalLabel,
  error,
  errorId,
  children,
}: {
  label: string;
  htmlFor: string;
  required?: boolean;
  hint?: string;
  optionalLabel?: string;
  error?: string | null;
  errorId?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label
        htmlFor={htmlFor}
        className="mb-1 flex items-center gap-2 text-sm font-medium text-slate-800"
      >
        {label}
        {required ? <span className="text-red-500">*</span> : null}
        {optionalLabel ? (
          <span className="text-xs font-normal text-slate-400">{optionalLabel}</span>
        ) : null}
      </label>
      {children}
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
      {error ? (
        <p id={errorId} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      ) : null}
    </div>
  );
}

declare global {
  interface Window {
    turnstile?: {
      getResponse: (element?: HTMLElement | string) => string | undefined;
      reset: (element?: HTMLElement | string) => void;
    };
  }
}
