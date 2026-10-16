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

/** Generates an Archimedean spiral path string. */
function spiralPath({
  cx,
  cy,
  a = 4,
  b = 2.4,
  turns = 2.2,
  rotate = 0,
}: {
  cx: number;
  cy: number;
  a?: number;
  b?: number;
  turns?: number;
  rotate?: number;
}) {
  let d = '';
  const points = 140;
  const twoPi = 2 * Math.PI;
  for (let i = 0; i <= points; i++) {
    const t = (i / points) * turns * twoPi + rotate;
    const r = a + b * t;
    const x = cx + r * Math.cos(t);
    const y = cy + r * Math.sin(t);
    d += `${i === 0 ? 'M' : 'L'} ${x.toFixed(2)},${y.toFixed(2)}`;
  }
  return d;
}

/** Spiral background that circles the site logo. */
function LogoSpiral() {
  return (
    <svg
      viewBox="0 0 64 32"
      className="absolute inset-0 w-full h-full pointer-events-none select-none"
      aria-hidden="true"
    >
      <path
        d={spiralPath({ cx: 32, cy: 16, a: 3, b: 2.6, turns: 2.2 })}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.3"
        strokeLinecap="round"
        className="text-orange-500/70 drop-shadow-[0_0_8px_rgba(234,88,12,0.3)]"
      />
      <circle cx="32" cy="16" r="1.2" fill="currentColor" className="text-orange-600" />
    </svg>
  );
}

/** Spiral oval background behind the nav bar links (buttons sit on its oval). */
function NavSpiral() {
  return (
    <svg
      viewBox="0 0 200 56"
      className="absolute left-1/2 -translate-x-1/2 top-1/2 -translate-y-1/2 w-[260px] h-[56px] pointer-events-none select-none"
      aria-hidden="true"
    >
      <path
        d={spiralPath({ cx: 100, cy: 28, a: 12, b: 5, turns: 1.5 })}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        className="text-orange-400/50 drop-shadow-[0_0_10px_rgba(234,88,12,0.25)]"
      />
    </svg>
  );
}

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
      <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-3 px-4 sm:px-6">
        <Link href="/" className="flex items-center gap-3 relative" aria-label={clinicName}>
          <div className="relative group inline-flex items-center">
            <LogoSpiral />
            <SiteLogo height={34} priority className="relative z-10" />
          </div>
          <span className="hidden flex-col justify-center leading-tight sm:flex lg:flex">
            <span className="text-sm font-bold tracking-tight text-slate-900">{clinicName}</span>
            <span className="text-[11px] font-medium text-orange-800">{t('clinicNameAm')}</span>
          </span>
        </Link>

        <nav className="ml-4 relative hidden items-center gap-0.5 lg:flex" aria-label={t('menuLabel')}>
          <NavSpiral />
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
