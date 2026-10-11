import type { Metadata } from 'next';
import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { getPublicDoctorStatus } from '@/modules/hr';
import { getClinicProfile, getSiteContent, pageMetadata, pick } from '@/modules/public-site';
import { telHref } from '@/modules/public-site/format';
import DoctorAvatar from '@/modules/public-site/components/DoctorAvatar';
import DoctorStatusBadge from '@/modules/public-site/components/DoctorStatusBadge';
import { getLocale } from 'next-intl/server';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.doctorsTitle'),
    description: t('meta.doctorsDescription'),
    path: '/doctors',
  });
}

export default async function DoctorsPage() {
  const t = await getTranslations('site');
  const locale = await getLocale();
  const [doctors, profile, content] = await Promise.all([
    getPublicDoctorStatus(),
    getClinicProfile(),
    getSiteContent(),
  ]);
  const phone = pick(locale, content.contact_phone);
  const phoneHref = telHref(phone);

  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('doctors.title')}
      </h1>
      <p className="mt-3 max-w-2xl text-lg text-slate-600">{t('doctors.intro')}</p>

      {doctors.length === 0 ? (
        <p className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
          {t('doctors.empty')}
        </p>
      ) : (
        <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {doctors.map((doctor) => (
            <article
              key={doctor.id}
              className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6"
            >
              <div className="flex items-center gap-4">
                <DoctorAvatar
                  doctorId={doctor.id}
                  name={doctor.name}
                  hasPhoto={Boolean(doctor.photoUrl)}
                  alt={t('doctors.photoAlt', { name: doctor.name })}
                  size={64}
                />
                <div>
                  <h2 className="text-lg font-semibold text-slate-900">{doctor.name}</h2>
                  {doctor.specialty ? (
                    <p className="text-sm text-slate-500">{doctor.specialty}</p>
                  ) : null}
                </div>
              </div>

              <div className="mt-4">
                <DoctorStatusBadge
                  status={doctor.status}
                  nextShiftStartsAt={doctor.nextShiftStartsAt}
                  timeZone={profile.timezone}
                />
              </div>

              {doctor.todayShifts.length ? (
                <ul className="mt-4 space-y-1 text-sm text-slate-600">
                  {doctor.todayShifts.map((shift, index) => (
                    <li key={index}>
                      {shift.start}–{shift.end}
                    </li>
                  ))}
                </ul>
              ) : null}

              <div className="mt-5 flex-1" />
              <div className="flex gap-3">
                <Link
                  href="/book"
                  className="rounded-xl bg-orange-800 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-900"
                >
                  {t('bookAppointment')}
                </Link>
                {phoneHref ? (
                  <a
                    href={phoneHref}
                    className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  >
                    {t('doctors.callToBook')}
                  </a>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
