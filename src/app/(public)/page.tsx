import type { Metadata } from 'next';
import Link from 'next/link';
import { getLocale, getTranslations } from 'next-intl/server';
import { getPublicDoctorStatus } from '@/modules/hr';
import {
  getClinicProfile,
  getPublicServices,
  getSiteContent,
  isTestimonial,
  pageMetadata,
  parseItems,
  pick,
} from '@/modules/public-site';
import { telHref } from '@/modules/public-site/format';
import DoctorAvatar from '@/modules/public-site/components/DoctorAvatar';
import DoctorStatusBadge from '@/modules/public-site/components/DoctorStatusBadge';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.homeTitle'),
    description: t('meta.homeDescription'),
    path: '/',
    isHome: true,
  });
}

export default async function HomePage() {
  const t = await getTranslations('site');
  const locale = await getLocale();
  const [content, services, profile, doctors] = await Promise.all([
    getSiteContent(),
    getPublicServices(),
    getClinicProfile(),
    getPublicDoctorStatus(),
  ]);

  const phone = pick(locale, content.contact_phone);
  const phoneHref = telHref(phone);
  const featured = services.slice(0, 6);
  const onDuty = doctors.filter((doctor) => doctor.status === 'ON_DUTY_NOW');
  const shownDoctors = (onDuty.length ? onDuty : doctors).slice(0, 4);
  const testimonials = parseItems(pick(locale, content.testimonials_items), isTestimonial).slice(
    0,
    3,
  );
  const address = pick(locale, content.contact_address);

  return (
    <>
      <section className="relative overflow-hidden bg-white">
        <div
          className="absolute inset-0"
          style={{
            backgroundImage: 'url(/hero-medical.svg)',
            backgroundSize: 'cover',
            backgroundPosition: 'center',
          }}
          aria-hidden="true"
        />
        <div
          className="absolute inset-0 bg-gradient-to-r from-white via-white/70 to-white/10"
          aria-hidden="true"
        />
        <div className="relative mx-auto grid w-full max-w-6xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:items-center lg:py-24">
          <div>
            <p className="inline-flex rounded-full bg-white px-3 py-1 text-sm font-medium text-teal-800 ring-1 ring-teal-100">
              {pick(locale, content.hero_promise)}
            </p>
            <h1 className="mt-5 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
              {pick(locale, content.hero_title)}
            </h1>
            <p className="mt-5 max-w-xl text-lg text-slate-600">
              {pick(locale, content.hero_subtitle)}
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Link
                href="/book"
                className="rounded-lg bg-teal-700 px-6 py-3 text-base font-semibold text-white hover:bg-teal-800"
              >
                {t('bookAppointment')}
              </Link>
              {phoneHref ? (
                <a
                  href={phoneHref}
                  className="rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-800 hover:bg-slate-50"
                >
                  {t('callNow')}
                </a>
              ) : null}
            </div>
          </div>
          <div className="rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-100">
            <h2 className="text-lg font-semibold text-slate-900">{t('home.whyTitle')}</h2>
            <ul className="mt-5 space-y-5">
              {(
                [
                  ['home.why1Title', 'home.why1Text'],
                  ['home.why2Title', 'home.why2Text'],
                  ['home.why3Title', 'home.why3Text'],
                ] as const
              ).map(([titleKey, textKey]) => (
                <li key={titleKey} className="flex gap-4">
                  <span className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-100 text-teal-800">
                    <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
                      <path
                        d="M5 10.5 8.5 14 15 6.5"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </span>
                  <div>
                    <p className="font-medium text-slate-900">{t(titleKey)}</p>
                    <p className="text-sm text-slate-600">{t(textKey)}</p>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      <section className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
        <h2 className="text-2xl font-bold text-slate-900 sm:text-3xl">{t('home.servicesTitle')}</h2>
        <p className="mt-2 max-w-2xl text-slate-600">{t('home.servicesIntro')}</p>
        {featured.length ? (
          <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {featured.map((service) => (
              <article
                key={service.id}
                className="rounded-2xl border border-slate-200 bg-white p-6 transition-shadow hover:shadow-md"
              >
                <span className="text-xs font-semibold uppercase tracking-wide text-teal-700">
                  {service.category}
                </span>
                <h3 className="mt-2 text-lg font-semibold text-slate-900">
                  {locale === 'am' ? service.nameAm || service.name : service.name}
                </h3>
                {service.descriptionEn || service.descriptionAm ? (
                  <p className="mt-2 text-sm text-slate-600">
                    {locale === 'am'
                      ? service.descriptionAm || service.descriptionEn
                      : service.descriptionEn}
                  </p>
                ) : null}
              </article>
            ))}
          </div>
        ) : (
          <p className="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
            {t('services.empty')}
          </p>
        )}
        <div className="mt-8">
          <Link href="/services" className="font-semibold text-teal-800 hover:text-teal-900">
            {t('home.viewAllServices')} →
          </Link>
        </div>
      </section>

      <section className="bg-slate-50">
        <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
          <h2 className="text-2xl font-bold text-slate-900 sm:text-3xl">
            {t('home.doctorsTitle')}
          </h2>
          <p className="mt-2 text-slate-600">{t('home.doctorsIntro')}</p>
          {shownDoctors.length ? (
            <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {shownDoctors.map((doctor) => (
                <div
                  key={doctor.id}
                  className="rounded-2xl border border-slate-200 bg-white p-6 text-center"
                >
                  <div className="flex justify-center">
                    <DoctorAvatar
                      doctorId={doctor.id}
                      name={doctor.name}
                      hasPhoto={Boolean(doctor.photoUrl)}
                      alt={t('doctors.photoAlt', { name: doctor.name })}
                      size={72}
                    />
                  </div>
                  <h3 className="mt-4 font-semibold text-slate-900">{doctor.name}</h3>
                  {doctor.specialty ? (
                    <p className="text-sm text-slate-500">{doctor.specialty}</p>
                  ) : null}
                  <div className="mt-3 flex justify-center">
                    <DoctorStatusBadge
                      status={doctor.status}
                      nextShiftStartsAt={doctor.nextShiftStartsAt}
                      timeZone={profile.timezone}
                    />
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="mt-6 text-slate-600">{t('doctors.empty')}</p>
          )}
          <div className="mt-8">
            <Link href="/doctors" className="font-semibold text-teal-800 hover:text-teal-900">
              {t('home.viewAllDoctors')} →
            </Link>
          </div>
        </div>
      </section>

      {testimonials.length ? (
        <section className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
          <h2 className="text-2xl font-bold text-slate-900 sm:text-3xl">
            {t('home.testimonialsTitle')}
          </h2>
          <p className="mt-2 text-slate-600">{t('home.testimonialsIntro')}</p>
          <div className="mt-8 grid gap-5 sm:grid-cols-3">
            {testimonials.map((item, index) => (
              <blockquote
                key={index}
                className="rounded-2xl border border-slate-200 bg-white p-6 text-slate-700"
              >
                <p className="text-sm">“{item.text}”</p>
                <footer className="mt-4 text-sm font-semibold text-slate-900">{item.name}</footer>
              </blockquote>
            ))}
          </div>
          <p className="mt-4 text-xs text-slate-400">{t('home.testimonialsPlaceholder')}</p>
        </section>
      ) : null}

      <section className="bg-teal-800 text-white">
        <div className="mx-auto grid w-full max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-2 md:items-center">
          <div>
            <h2 className="text-2xl font-bold sm:text-3xl">{t('home.ctaTitle')}</h2>
            <p className="mt-3 max-w-lg text-teal-50">{t('home.ctaText')}</p>
            <div className="mt-6 flex flex-wrap gap-3">
              <Link
                href="/book"
                className="rounded-lg bg-white px-6 py-3 text-base font-semibold text-teal-800 hover:bg-teal-50"
              >
                {t('bookAppointment')}
              </Link>
              {phoneHref ? (
                <a
                  href={phoneHref}
                  className="rounded-lg border border-teal-200 px-6 py-3 text-base font-semibold text-white hover:bg-teal-700"
                >
                  {phone}
                </a>
              ) : null}
            </div>
          </div>
          <div className="rounded-2xl bg-teal-900/40 p-6 ring-1 ring-teal-700">
            <h3 className="text-lg font-semibold">{t('home.locationTitle')}</h3>
            {address ? <p className="mt-2 text-teal-50">{address}</p> : null}
            <h3 className="mt-5 text-lg font-semibold">{t('home.hoursTitle')}</h3>
            <p className="mt-2 text-teal-50">{pick(locale, content.hours_text)}</p>
          </div>
        </div>
      </section>
    </>
  );
}
