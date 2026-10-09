import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { clinicDateKey } from '@/lib/datetime';
import {
  BOOKING_WINDOWS,
  getClinicProfile,
  getPublicServices,
  pageMetadata,
} from '@/modules/public-site';
import BookingForm from '@/modules/public-site/components/BookingForm';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.bookTitle'),
    description: t('meta.bookDescription'),
    path: '/book',
  });
}

export default async function BookPage() {
  const t = await getTranslations('site');
  const [services, profile] = await Promise.all([getPublicServices(), getClinicProfile()]);
  const minDate = clinicDateKey(new Date(), profile.timezone);

  return (
    <div className="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('book.title')}
      </h1>
      <p className="mt-3 text-lg text-slate-600">{t('book.intro')}</p>
      <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
        <BookingForm services={services} windows={[...BOOKING_WINDOWS]} minDate={minDate} />
      </div>
    </div>
  );
}
