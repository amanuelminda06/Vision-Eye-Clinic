import type { Metadata } from 'next';
import { getLocale, getTranslations } from 'next-intl/server';
import { clinicDateKey } from '@/lib/datetime';
import {
  getClinicProfile,
  getPublicServices,
  getSiteContent,
  isAnnouncementVisible,
  pick,
  siteBaseUrl,
} from '@/modules/public-site';
import { telHref } from '@/modules/public-site/format';
import PublicHeader from '@/modules/public-site/components/PublicHeader';
import PublicFooter from '@/modules/public-site/components/PublicFooter';
import AnnouncementBanner from '@/modules/public-site/components/AnnouncementBanner';
import Analytics from '@/modules/public-site/components/Analytics';
import JsonLd from '@/modules/public-site/components/JsonLd';

export const instant = false;

export async function generateMetadata(): Promise<Metadata> {
  const profile = await getClinicProfile();
  return {
    metadataBase: new URL(siteBaseUrl()),
    applicationName: profile.name,
    openGraph: { siteName: profile.name },
  };
}

export default async function PublicLayout({ children }: { children: React.ReactNode }) {
  const [content, profile, services] = await Promise.all([
    getSiteContent(),
    getClinicProfile(),
    getPublicServices(),
  ]);
  const locale = await getLocale();
  const t = await getTranslations('site');

  const clinicName = profile.name;
  const address = pick(locale, content.contact_address) || null;
  const phone = pick(locale, content.contact_phone) || null;
  const phoneHref = telHref(phone);
  const hoursText = pick(locale, content.hours_text) || null;
  const footerNote = pick(locale, content.footer_note) || null;
  const announcementText = pick(locale, content.announcement_text);

  const showAnnouncement = isAnnouncementVisible(
    content,
    new Date(),
    profile.timezone,
    clinicDateKey,
  );

  const jsonLd: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'MedicalClinic',
    name: clinicName,
    url: siteBaseUrl(),
    ...(phone ? { telephone: phone } : {}),
    ...(address
      ? {
          address: {
            '@type': 'PostalAddress',
            streetAddress: address,
            addressLocality: 'Addis Ababa',
            addressCountry: 'ET',
          },
        }
      : {}),
    ...(hoursText ? { openingHours: hoursText } : {}),
    medicalSpecialty: 'Ophthalmology',
    ...(services.length
      ? {
          availableService: services.map((service) => ({
            '@type': 'MedicalProcedure',
            name: locale === 'am' ? service.nameAm || service.name : service.name,
          })),
        }
      : {}),
  };

  return (
    <div className="flex min-h-screen flex-col bg-white text-slate-900">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-teal-800 focus:px-4 focus:py-2 focus:text-white"
      >
        {t('skipToContent')}
      </a>
      {showAnnouncement ? <AnnouncementBanner text={announcementText} /> : null}
      <PublicHeader clinicName={clinicName} phoneHref={phoneHref} />
      <main id="main" className="flex-1">
        {children}
      </main>
      <PublicFooter
        clinicName={clinicName}
        address={address}
        phone={phone}
        phoneHref={phoneHref}
        hoursText={hoursText}
        footerNote={footerNote}
      />
      <JsonLd data={jsonLd} />
      <Analytics />
    </div>
  );
}
