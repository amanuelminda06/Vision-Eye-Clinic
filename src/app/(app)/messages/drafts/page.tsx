import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { MESSAGE_ROLES, listDrafts } from '@/modules/messaging';
import DraftsBoard from '@/modules/messaging/components/DraftsBoard';

export default async function DraftsPage() {
  const user = await requirePageRole(MESSAGE_ROLES);
  const t = await getTranslations('messages');
  const drafts = await listDrafts(user.id);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('draftsTitle')}</h1>
          <p className="text-sm text-slate-500">{t('draftsSubtitle')}</p>
        </div>
        <Link href="/messages" className="btn-secondary">
          {t('backToMessages')}
        </Link>
      </div>
      <DraftsBoard drafts={drafts} />
    </div>
  );
}
