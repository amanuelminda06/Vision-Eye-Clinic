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
 * Cookie-based locale switcher (no URL prefix, so `/login/<secret>` stays
 * clean). Sets the `locale` cookie and refreshes the server components.
 */
export default function LocaleSwitcher() {
  const router = useRouter();
  const active = useLocale();
  const t = useTranslations('common');

  function switchTo(code: Locale) {
    setLocaleCookie(code);
    router.refresh();
  }

  return (
    <div
      className="flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-0.5"
      role="group"
      aria-label={t('language')}
    >
      {LOCALES.map(({ code, label }) => (
        <button
          key={code}
          type="button"
          onClick={() => switchTo(code)}
          aria-pressed={active === code}
          className={`rounded-md px-2 py-1 text-xs font-medium transition-colors ${
            active === code ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
          }`}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
