'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useState } from 'react';
import { useTranslations } from 'next-intl';
import SiteLogo from '@/components/SiteLogo';
import PublicLocaleSwitcher from './PublicLocaleSwitcher';

type NavLink = { href: string; labelKey: string };

const LINKS: NavLink[] = [
  { href: '/', labelKey: 'navHome' },
  { href: '/about', labelKey: 'navAbout' },
  { href: '/services', labelKey: 'navServices' },
  { href: '/doctors', labelKey: 'navDoctors' },
  { href: '/contact', labelKey: 'navContact' },
  { href: '/faq', labelKey: 'navFaq' },
];

type Props = {
  clinicName: string;
  phoneHref: string | null;
};

export default function PublicHeader({ clinicName, phoneHref }: Props) {
  const t = useTranslations('site');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  const isActive = (href: string) => (href === '/' ? pathname === '/' : pathname.startsWith(href));

  const links = (onClick?: () => void, className = '') =>
    LINKS.map((link) => (
      <Link
        key={link.href}
        href={link.href}
        onClick={onClick}
        aria-current={isActive(link.href) ? 'page' : undefined}
        className={`relative rounded-xl px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 ${
          isActive(link.href)
            ? 'after:absolute after:inset-x-3 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-orange-600 text-orange-900'
            : 'text-slate-600 hover:bg-orange-50 hover:text-orange-800'
        } ${className}`}
      >
        {t(link.labelKey as never)}
      </Link>
    ));



  return (
    <header className="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <div className="flex h-16 w-full items-center gap-3 rounded-full bg-slate-200 px-5 sm:px-6">
          <Link href="/" className="flex items-center gap-3" aria-label={clinicName}>
            <SiteLogo height={34} priority />
            <span className="hidden flex-col justify-center leading-tight sm:flex lg:flex">
              <span className="text-sm font-bold tracking-tight text-slate-900">{clinicName}</span>
              <span className="text-[11px] font-medium text-orange-800">{t('clinicNameAm')}</span>
            </span>
          </Link>

          <nav className="ml-4 hidden items-center gap-0.5 lg:flex" aria-label={t('menuLabel')}>
            {links()}
          </nav>

          <div className="ml-auto flex items-center gap-2">
            <div className="hidden sm:block">
              <PublicLocaleSwitcher />
            </div>
            {phoneHref ? (
              <a
                href={phoneHref}
                className="hidden items-center rounded-xl border border-orange-200 px-3 py-2 text-sm font-medium text-orange-900 transition-colors hover:bg-orange-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 md:inline-flex"
              >
                {t('callNow')}
              </a>
            ) : null}
            <Link
              href="/book"
              className="hidden rounded-xl bg-orange-800 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-orange-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 sm:inline-flex"
            >
              {t('bookAppointment')}
            </Link>

            <button
              type="button"
              className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600 lg:hidden"
              aria-expanded={open}
              aria-controls="public-mobile-nav"
              aria-label={open ? t('menuClose') : t('menuLabel')}
              onClick={() => setOpen((value) => !value)}
            >
              {open ? (
                <svg viewBox="0 0 24 24" className="h-5 w-5" aria-hidden="true" fill="none">
                  <path
                    d="M6 6l12 12M18 6 6 18"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                  />
                </svg>
              ) : (
                <svg viewBox="0 0 24 24" className="h-5 w-5" aria-hidden="true" fill="none">
                  <path
                    d="M4 7h16M4 12h16M4 17h16"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                  />
                </svg>
              )}
            </button>
          </div>
        </div>
      </div>

      <div
        className={`overflow-hidden border-t transition-all duration-200 ease-out ${
          open
            ? 'max-h-96 border-orange-100 opacity-100'
            : 'invisible max-h-0 border-transparent opacity-0'
        } lg:hidden`}
      >
        <nav id="public-mobile-nav" className="bg-white px-4 pb-4 pt-2" aria-label={t('menuLabel')}>
          <div className="flex flex-col gap-1">
            {links(() => setOpen(false))}
          </div>
          <div className="mt-3 flex items-center gap-3">
            <PublicLocaleSwitcher />
            {phoneHref ? (
              <a
                href={phoneHref}
                className="flex-1 rounded-xl border border-orange-200 px-4 py-2 text-center text-sm font-medium text-orange-900"
              >
                {t('callNow')}
              </a>
            ) : null}
            <Link
              href="/book"
              onClick={() => setOpen(false)}
              className="flex-1 rounded-xl bg-orange-800 px-4 py-2 text-center text-sm font-semibold text-white shadow-sm"
            >
              {t('bookAppointment')}
            </Link>
          </div>
        </nav>
      </div>
    </header>
  );
}
