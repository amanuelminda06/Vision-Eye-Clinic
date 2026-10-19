'use client';

import { useState } from 'react';
import { useLocale, useTranslations } from 'next-intl';

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY;

const ERROR_CODES = ['required', 'tooLong', 'invalidEmail', 'invalidRating', 'consent'] as const;
type ErrorCode = (typeof ERROR_CODES)[number];

function errorLabel(code: string | undefined): ErrorCode | null {
  return code && (ERROR_CODES as readonly string[]).includes(code) ? (code as ErrorCode) : null;
}

const RATING_KEYS = ['', '1', '2', '3', '4', '5'] as const;
const RATING_LABEL_KEYS: Record<string, string> = {
  '': 'ratingNone',
  '1': 'ratingPoor',
  '2': 'ratingFair',
  '3': 'ratingGood',
  '4': 'ratingVeryGood',
  '5': 'ratingExcellent',
};

type Status = 'idle' | 'submitting' | 'success' | 'error';

/**
 * Public feedback collector (contact page). Submissions land in the ERP's CMS
 * as hidden entries — they only appear in the website's testimonials after the
 * clinic approves them with the "show on website" checkbox.
 */
export default function FeedbackForm() {
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
      email: String(data.get('email') ?? ''),
      rating: String(data.get('rating') ?? ''),
      message: String(data.get('message') ?? ''),
      language: locale,
      consent: data.get('consent') === 'on',
      website: String(data.get('website') ?? ''),
      turnstileToken,
    };

    setStatus('submitting');
    setError(null);
    setFields({});

    try {
      const response = await fetch('/api/public/feedback', {
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
        setError(t('feedback.errorRateLimited'));
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
      setError(
        json.error === 'validationError'
          ? t('feedback.errorValidation')
          : t('feedback.errorGeneric'),
      );
    } catch {
      setStatus('error');
      setError(t('feedback.errorGeneric'));
    }
  }

  if (status === 'success') {
    return (
      <div
        role="status"
        className="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center"
      >
        <p className="text-lg font-semibold text-emerald-900">{t('feedback.successTitle')}</p>
        <p className="mt-1 text-sm text-emerald-800">{t('feedback.successBody')}</p>
        <button
          type="button"
          onClick={() => setStatus('idle')}
          className="mt-4 rounded-lg border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100"
        >
          {t('feedback.successAgain')}
        </button>
      </div>
    );
  }

  const fieldError = (name: string) => {
    const code = errorLabel(fields[name]);
    if (code) return t(`feedback.errors.${code}` as never);
    if (fields[name] === 'invalidLanguage' || fields[name] === 'spam') {
      return t('feedback.errorValidation');
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
          label={t('feedback.name')}
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
            placeholder={t('feedback.namePlaceholder')}
            aria-describedby={describedBy('name')}
            aria-invalid={fieldError('name') ? true : undefined}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-100"
          />
        </Field>

        <Field
          label={t('feedback.email')}
          htmlFor="email"
          hint={t('feedback.emailHint')}
          optionalLabel={t('feedback.optional')}
          error={fieldError('email')}
          errorId="email-error"
        >
          <input
            id="email"
            name="email"
            type="email"
            maxLength={254}
            autoComplete="email"
            placeholder={t('feedback.emailPlaceholder')}
            aria-describedby={describedBy('email')}
            aria-invalid={fieldError('email') ? true : undefined}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-100"
          />
        </Field>
      </div>

      <Field
        label={t('feedback.rating')}
        htmlFor="rating"
        optionalLabel={t('feedback.optional')}
        error={fieldError('rating')}
        errorId="rating-error"
      >
        <select
          id="rating"
          name="rating"
          defaultValue=""
          aria-describedby={describedBy('rating')}
          className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base focus:border-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-100"
        >
          {RATING_KEYS.map((value) => (
            <option key={value} value={value}>
              {t(`feedback.${RATING_LABEL_KEYS[value]}` as never)}
            </option>
          ))}
        </select>
      </Field>

      <Field
        label={t('feedback.message')}
        htmlFor="message"
        hint={t('feedback.messageHint', { max: 1000 })}
        required
        error={fieldError('message')}
        errorId="message-error"
      >
        <textarea
          id="message"
          name="message"
          rows={4}
          maxLength={1000}
          placeholder={t('feedback.messagePlaceholder')}
          aria-describedby={describedBy('message')}
          aria-invalid={fieldError('message') ? true : undefined}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base focus:border-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-100"
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
          className="mt-0.5 h-4 w-4 rounded border-slate-300 text-orange-700 focus:ring-orange-500"
        />
        <span>
          {t('feedback.consent')}
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
          className="rounded-xl bg-orange-800 px-6 py-3 text-base font-semibold text-white hover:bg-orange-900 disabled:opacity-60"
        >
          {status === 'submitting' ? t('feedback.submitting') : t('feedback.submit')}
        </button>
        <p className="text-xs text-slate-500">{t('feedback.requiredNote')}</p>
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
