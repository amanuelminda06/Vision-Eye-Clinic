import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import { getLocale, getTranslations } from 'next-intl/server';
import { CATEGORY_LABEL_KEYS, isServiceCategory } from '@/modules/services/categories';
import { getPublicServices, pageMetadata, publicServiceImageUrl } from '@/modules/public-site';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('site');
  return pageMetadata({
    title: t('meta.servicesTitle'),
    description: t('meta.servicesDescription'),
    path: '/services',
  });
}

/** Entrance animation shared by every service card. */
function ServiceAnimationStyles() {
  return (
    <style>
      {`@keyframes svc-rise {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
      }
      .svc-card {
        animation: svc-rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
      }
      .svc-card:hover { transform: translateY(-4px); transition: transform 0.2s ease; }`}
    </style>
  );
}

export default async function ServicesPage() {
  const t = await getTranslations('site');
  const ts = await getTranslations('services');
  const locale = await getLocale();
  const services = await getPublicServices();

  const byCategory = new Map<string, typeof services>();
  for (const service of services) {
    const list = byCategory.get(service.category) ?? [];
    list.push(service);
    byCategory.set(service.category, list);
  }

  function categoryLabel(category: string): string {
    if (isServiceCategory(category)) return ts(CATEGORY_LABEL_KEYS[category] as never);
    return category;
  }

  return (
    <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
      <ServiceAnimationStyles />
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
                {categoryLabel(category)}
              </h2>
              <div className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {list.map((service) => {
                  const name =
                    locale === 'am'
                      ? service.nameAm || service.nameEn || service.name
                      : service.nameEn || service.name;
                  const description =
                    locale === 'am'
                      ? service.descriptionAm || service.descriptionEn
                      : service.descriptionEn;
                  return (
                    <article
                      key={service.id}
                      className="svc-card flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                    >
                      {service.imageUrl ? (
                        <Image
                          src={publicServiceImageUrl(service.id, service.imageUrl)!}
                          alt={name}
                          width={1200}
                          height={800}
                          className="h-40 w-full object-cover transition-transform duration-300 hover:scale-[1.03]"
                        />
                      ) : null}
                      <div className="flex flex-1 flex-col p-6">
                        <h3 className="text-lg font-semibold text-slate-900">{name}</h3>
                        {description ? (
                          <p className="mt-2 flex-1 text-sm text-slate-600">{description}</p>
                        ) : (
                          <div className="flex-1" />
                        )}
                        <Link
                          href="/book"
                          className="mt-4 inline-flex items-center justify-center rounded-xl bg-orange-800 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-orange-900"
                        >
                          {t('services.bookService')} →
                        </Link>
                      </div>
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