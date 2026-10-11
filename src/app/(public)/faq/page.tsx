import type { Metadata } from 'next';
import Link from 'next/link';
import { getLocale, getTranslations } from 'next-intl/server';
import { getSiteContent, isFaqItem, pageMetadata, parseItems, pick } from '@/modules/public-site';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.faqTitle'),
    description: t('meta.faqDescription'),
    path: '/faq',
  });
}

export default async function FaqPage() {
  const t = await getTranslations('site');
  const locale = await getLocale();
  const content = await getSiteContent();
  const items = parseItems(pick(locale, content.faq_items), isFaqItem);

  return (
    <div className="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('faq.title')}
      </h1>
      <p className="mt-3 text-lg text-slate-600">{t('faq.intro')}</p>

      {items.length === 0 ? (
        <p className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
          {t('faq.empty')}
        </p>
      ) : (
        <div className="mt-8 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
          {items.map((item, index) => (
            <details key={index} className="group px-6 py-4">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-900">
                {item.question}
                <span
                  aria-hidden="true"
                  className="text-orange-700 transition-transform group-open:rotate-45"
                >
                  +
                </span>
              </summary>
              <p className="mt-3 text-slate-600">{item.answer}</p>
            </details>
          ))}
        </div>
      )}

      <div className="mt-8">
        <Link href="/contact" className="font-semibold text-orange-800 hover:text-orange-900">
          {t('navContact')} →
        </Link>
      </div>
    </div>
  );
}
