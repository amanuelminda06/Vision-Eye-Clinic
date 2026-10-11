import type { Metadata } from 'next';
import Link from 'next/link';
import { getLocale, getTranslations } from 'next-intl/server';
import { getPublicServices, pageMetadata } from '@/modules/public-site';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.servicesTitle'),
    description: t('meta.servicesDescription'),
    path: '/services',
  });
}

export default async function ServicesPage() {
  const t = await getTranslations('site');
  const locale = await getLocale();
  const services = await getPublicServices();

  const byCategory = new Map<string, typeof services>();
  for (const service of services) {
    const list = byCategory.get(service.category) ?? [];
    list.push(service);
    byCategory.set(service.category, list);
  }

  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
        {t('services.title')}
      </h1>
      <p className="mt-3 max-w-2xl text-lg text-slate-600">{t('services.intro')}</p>

      {services.length === 0 ? (
        <p className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
          {t('services.empty')}
        </p>
      ) : (
        <div className="mt-10 space-y-12">
          {[...byCategory.entries()].map(([category, list]) => (
            <section key={category}>
              <h2 className="text-xl font-bold text-slate-900">
                <span className="text-sm font-semibold uppercase tracking-wide text-orange-700">
                  {t('services.category')}:{' '}
                </span>
                {category}
              </h2>
              <div className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {list.map((service) => {
                  const name = locale === 'am' ? service.nameAm || service.name : service.name;
                  const description =
                    locale === 'am'
                      ? service.descriptionAm || service.descriptionEn
                      : service.descriptionEn;
                  return (
                    <article
                      key={service.id}
                      className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6"
                    >
                      <h3 className="text-lg font-semibold text-slate-900">{name}</h3>
                      {description ? (
                        <p className="mt-2 flex-1 text-sm text-slate-600">{description}</p>
                      ) : (
                        <div className="flex-1" />
                      )}
                      <Link
                        href="/book"
                        className="mt-4 font-semibold text-orange-800 hover:text-orange-900"
                      >
                        {t('services.bookService')} →
                      </Link>
                    </article>
                  );
                })}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
