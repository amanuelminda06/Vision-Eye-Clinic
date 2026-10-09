import type { Metadata } from 'next';
import { getLocale, getTranslations } from 'next-intl/server';
import { getSiteContent, pageMetadata, pick } from '@/modules/public-site';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.privacyTitle'),
    description: t('meta.privacyDescription'),
    path: '/privacy',
  });
}

export default async function PrivacyPage() {
  const t = await getTranslations('site');
  const locale = await getLocale();
  const content = await getSiteContent();
  const body = pick(locale, content.privacy_body)
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean);

  return (
    <div className="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('privacy.title')}
      </h1>
      <p className="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        {t('privacy.updated')}
      </p>
      <div className="mt-6 space-y-4 text-slate-700">
        {body.map((paragraph, index) => (
          <p key={index}>{paragraph}</p>
        ))}
      </div>
    </div>
  );
}
