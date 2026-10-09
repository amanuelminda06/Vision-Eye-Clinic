import Link from 'next/link';
import { getTranslations } from 'next-intl/server';

export default async function ForbiddenPage() {
  const t = await getTranslations('forbidden');
  const tc = await getTranslations('common');

  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <p className="text-6xl font-semibold text-slate-200">403</p>
      <h1 className="mt-4 text-xl font-semibold text-slate-900">{t('title')}</h1>
      <p className="mt-2 text-sm text-slate-600">{t('message')}</p>
      <Link href="/" className="btn-primary mt-6 inline-flex">
        {tc('back')}
      </Link>
    </div>
  );
}
