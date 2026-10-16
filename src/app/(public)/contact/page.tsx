import type { Metadata } from 'next';
import { getLocale, getTranslations } from 'next-intl/server';
import {
  getClinicProfile,
  getSiteContent,
  pageMetadata,
  pick,
  workingHoursLines,
  type WorkingHours,
} from '@/modules/public-site';
import { telHref, telegramHref, whatsappHref } from '@/modules/public-site/format';
import Map from '@/modules/public-site/components/Map';

const DAY_KEYS: Record<keyof WorkingHours, string> = {
  mon: 'dayMon',
  tue: 'dayTue',
  wed: 'dayWed',
  thu: 'dayThu',
  fri: 'dayFri',
  sat: 'daySat',
  sun: 'daySun',
};

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.contactTitle'),
    description: t('meta.contactDescription'),
    path: '/contact',
  });
}

export default async function ContactPage() {
  const t = await getTranslations('site');
  const ts = await getTranslations('settings');
  const locale = await getLocale();
  const [content, profile] = await Promise.all([getSiteContent(), getClinicProfile()]);

  const address = pick(locale, content.contact_address);
  const phonePrimary = pick(locale, content.contact_phone_primary);
  const phonePrimaryHref = telHref(phonePrimary);
  const phoneBackup = pick(locale, content.contact_phone_backup);
  const mapUrl = pick(locale, content.contact_map_url);
  const mapLat = pick(locale, content.map_lat);
  const mapLng = pick(locale, content.map_lng);
  const mapZoom = pick(locale, content.map_zoom);
  const whatsapp = whatsappHref(phonePrimary);
  const telegram = telegramHref(phonePrimary);
  const hours = workingHoursLines(
    profile.workingHours,
    (day) => ts(DAY_KEYS[day] as never),
    ts('closed'),
  );

  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('contact.title')}
      </h1>
      <p className="mt-3 max-w-2xl text-lg text-slate-600">{t('contact.intro')}</p>

      <div className="mt-10 grid gap-6 lg:grid-cols-3">
        <div className="rounded-2xl border border-slate-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-slate-900">{t('contact.address')}</h2>
          <p className="mt-3 text-slate-600">{address || t('contact.pending')}</p>
          {mapUrl ? (
            <a
              href={mapUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-4 inline-flex items-center gap-2 rounded-lg bg-orange-800 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-900"
            >
              {/maps\.app\.goo\.gl/i.test(mapUrl) ? 'View on Google Maps' : t('contact.directions')} →
            </a>
          ) : null}
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-slate-900">{t('contact.phone')}</h2>
          {phonePrimary && phonePrimaryHref ? (
            <>
              <a href={phonePrimaryHref} className="mt-3 block text-lg font-semibold text-orange-800">
                {phonePrimary}
              </a>
              {phoneBackup ? (
                <p className="mt-2 text-sm text-slate-600">{t('phoneBackupLabel', { number: phoneBackup })}</p>
              ) : null}
            </>
          ) : (
            <p className="mt-3 text-slate-600">{t('phonePending')}</p>
          )}
          <div className="mt-4 flex flex-wrap gap-3">
            {whatsapp ? (
              <a
                href={whatsapp}
                target="_blank"
                rel="noopener noreferrer"
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
              >
                {t('contact.whatsapp')}
              </a>
            ) : null}
            {telegram ? (
              <a
                href={telegram}
                target="_blank"
                rel="noopener noreferrer"
                className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700"
              >
                {t('contact.telegram')}
              </a>
            ) : null}
          </div>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-slate-900">{t('contact.hours')}</h2>
          <dl className="mt-3 space-y-1 text-sm">
            {hours.map((line) => (
              <div key={line.day} className="flex justify-between gap-4">
                <dt className="text-slate-600">{line.day}</dt>
                <dd className="font-medium text-slate-900">{line.value}</dd>
              </div>
            ))}
          </dl>
        </div>
      </div>

      <div className="mt-6">
        <h2 className="text-xl font-semibold text-slate-900 mb-2">{t('contact.map')}</h2>
        {mapLat && mapLng ? (
          <>
            <Map
              lat={parseFloat(mapLat)}
              lng={parseFloat(mapLng)}
              zoom={mapZoom ? parseFloat(mapZoom) : 15}
              height={320}
              openInMapsLabel={t('openInMaps')}
              title={t('contact.map')}
            />
            <p className="mt-2">
              <a
                href={`https://maps.app.goo.gl/Cyye5QKD3qjUWVXA6`}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-900 hover:bg-slate-50"
              >
                View on Google Maps
              </a>
            </p>
          </>
        ) : null}
      </div>
    </div>
  );
}
