import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { SiteLogoDark } from '@/components/SiteLogo';
import Map from './Map';

type SocialLink = { href: string; label: string; icon: string };

type Props = {
  clinicName: string;
  address: string | null;
  phone: string | null;
  phoneHref: string | null;
  hoursText: string | null;
  footerNote: string | null;
  socials: SocialLink[];
  mapLat?: string | null;
  mapLng?: string | null;
  mapZoom?: string | null;
};

export default async function PublicFooter({
  clinicName,
  address,
  phone,
  phoneHref,
  hoursText,
  footerNote,
  socials,
  mapLat,
  mapLng,
  mapZoom,
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
    <footer className="border-t-2 border-orange-600 bg-slate-50">
      <div className="mx-auto grid w-full max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3">
        <div>
          <div className="inline-flex rounded-2xl bg-stone-900 p-3 ring-1 ring-orange-600/60">
            <SiteLogoDark height={40} />
          </div>
          <p className="mt-3 max-w-xs text-sm text-slate-600">{t('footerAbout')}</p>
          {footerNote ? <p className="mt-3 max-w-xs text-xs text-slate-500">{footerNote}</p> : null}
          {socials.length ? (
            <div className="mt-4 flex flex-wrap gap-2">
              {socials.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={link.label}
                  className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-300 text-slate-600 hover:bg-orange-50 hover:text-orange-800"
                >
                  <span className="text-base" aria-hidden>
                    {link.icon}
                  </span>
                  <span className="sr-only">{link.label}</span>
                </a>
              ))}
            </div>
          ) : null}
        </div>

        <nav aria-label={t('footerQuickLinks')}>
          <h2 className="text-sm font-semibold text-slate-900">{t('footerQuickLinks')}</h2>
          <ul className="mt-3 space-y-2">
            {quickLinks.map((link) => (
              <li key={link.href}>
                <Link href={link.href} className="text-sm text-slate-600 hover:text-orange-800">
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
                <a href={phoneHref} className="hover:text-orange-800">
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
          {mapLat && mapLng ? (
            <div className="mt-5">
              <h2 className="text-sm font-semibold text-slate-900">{t('footerMap')}</h2>
              <div className="mt-3">
                <Map
                  lat={parseFloat(mapLat)}
                  lng={parseFloat(mapLng)}
                  zoom={mapZoom ? parseFloat(mapZoom) : 15}
                  height={180}
                  openInMapsLabel={t('openInMaps')}
                  title={clinicName}
                />
              </div>
            </div>
          ) : null}        </div>
      </div>

      <div className="border-t border-slate-200">
        <div className="mx-auto w-full max-w-6xl px-4 py-5 text-xs text-slate-500 sm:px-6">
          {t('footerRights', { year, clinic: clinicName })}
        </div>
      </div>
    </footer>
  );
}
