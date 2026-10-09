import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { CONTENT_ROLES } from '@/modules/public-site/roles';
import { getSiteContent } from '@/modules/public-site/queries';
import SiteContentForm from '@/modules/public-site/components/SiteContentForm';

export default async function WebsiteContentPage() {
  await requirePageRole(CONTENT_ROLES);
  const t = await getTranslations('content');
  const content = await getSiteContent();

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <Link href="/" target="_blank" className="btn-secondary">
          {t('viewSite')}
        </Link>
      </div>
      <p className="max-w-3xl text-sm text-slate-600">{t('intro')}</p>
      <SiteContentForm content={content} />
    </div>
  );
}
