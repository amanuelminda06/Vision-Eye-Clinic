'use client';

import { useRouter } from 'next/navigation';
import { useLocale, useTranslations } from 'next-intl';
import type { Locale } from '@/i18n/config';

const LOCALES: Array<{ code: Locale; label: string }> = [
  { code: 'en', label: 'EN' },
  { code: 'am', label: 'አማ' },
];

function setLocaleCookie(code: Locale): void {
  document.cookie = `locale=${code}; path=/; max-age=31536000; samesite=lax`;
}

/**
 * Public language toggle. Uses the cookie-based locale (no URL prefix), so
 * switching keeps the visitor on exactly the page they are viewing. The proxy
 * also understands `?lang=en|am` links (used for hreflang/crawlers).
 */
export default function PublicLocaleSwitcher() {
  const router = useRouter();
  const active = useLocale();
  const t = useTranslations('site');

  function switchTo(code: Locale) {
    setLocaleCookie(code);
    router.refresh();
  }

  return (
    <div
      className="inline-flex items-center rounded-full border border-slate-200 bg-white p-0.5"
      role="group"
      aria-label={t('languageLabel')}
    >
      {LOCALES.map(({ code, label }) => (
        <button
          key={code}
          type="button"
          lang={code}
          onClick={() => switchTo(code)}
          aria-pressed={active === code}
          className={`rounded-full px-2.5 py-1 text-xs font-semibold transition-colors ${
            active === code ? 'bg-orange-800 text-white' : 'text-slate-600 hover:bg-slate-100'
          }`}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
