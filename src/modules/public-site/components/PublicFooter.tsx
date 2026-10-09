import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { BrandMark } from './PublicHeader';

type Props = {
  clinicName: string;
  address: string | null;
  phone: string | null;
  phoneHref: string | null;
  hoursText: string | null;
  footerNote: string | null;
};

export default async function PublicFooter({
  clinicName,
  address,
  phone,
  phoneHref,
  hoursText,
  footerNote,
}: Props) {
  const t = await getTranslations('site');
  const year = new Date().getFullYear();

  const quickLinks = [
    { href: '/services', label: t('navServices') },
    { href: '/doctors', label: t('navDoctors') },
    { href: '/book', label: t('bookAppointment') },
    { href: '/contact', label: t('navContact') },
    { href: '/faq', label: t('navFaq') },
    { href: '/privacy', label: t('footerPrivacy') },
  ];

  return (
    <footer className="mt-16 border-t border-slate-200 bg-slate-50">
      <div className="mx-auto grid w-full max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3">
        <div>
          <div className="flex items-center gap-2.5">
            <BrandMark className="h-9 w-9" />
            <span className="text-base font-semibold text-slate-900">{clinicName}</span>
          </div>
          <p className="mt-3 max-w-xs text-sm text-slate-600">{t('footerAbout')}</p>
          {footerNote ? <p className="mt-3 max-w-xs text-xs text-slate-500">{footerNote}</p> : null}
        </div>

        <nav aria-label={t('footerQuickLinks')}>
          <h2 className="text-sm font-semibold text-slate-900">{t('footerQuickLinks')}</h2>
          <ul className="mt-3 space-y-2">
            {quickLinks.map((link) => (
              <li key={link.href}>
                <Link href={link.href} className="text-sm text-slate-600 hover:text-teal-800">
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <div>
          <h2 className="text-sm font-semibold text-slate-900">{t('footerContact')}</h2>
          <ul className="mt-3 space-y-2 text-sm text-slate-600">
            {address ? <li>{address}</li> : null}
            {phone && phoneHref ? (
              <li>
                <a href={phoneHref} className="hover:text-teal-800">
                  {phone}
                </a>
              </li>
            ) : null}
          </ul>
          {hoursText ? (
            <>
              <h2 className="mt-5 text-sm font-semibold text-slate-900">{t('footerHours')}</h2>
              <p className="mt-2 text-sm text-slate-600">{hoursText}</p>
            </>
          ) : null}
        </div>
      </div>

      <div className="border-t border-slate-200">
        <div className="mx-auto w-full max-w-6xl px-4 py-5 text-xs text-slate-500 sm:px-6">
          {t('footerRights', { year, clinic: clinicName })}
        </div>
      </div>
    </footer>
  );
}
