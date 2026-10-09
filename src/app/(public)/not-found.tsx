import Link from 'next/link';
import { getTranslations } from 'next-intl/server';

export default async function PublicNotFound() {
  const t = await getTranslations('site');
  return (
    <div className="mx-auto flex w-full max-w-xl flex-col items-center px-4 py-24 text-center sm:px-6">
      <p className="text-5xl font-bold text-teal-700">404</p>
      <h1 className="mt-4 text-2xl font-bold text-slate-900">{t('notFoundTitle')}</h1>
      <p className="mt-2 text-slate-600">{t('notFoundBody')}</p>
      <Link
        href="/"
        className="mt-6 rounded-lg bg-teal-700 px-6 py-3 text-base font-semibold text-white hover:bg-teal-800"
      >
        {t('backHome')}
      </Link>
    </div>
  );
}
