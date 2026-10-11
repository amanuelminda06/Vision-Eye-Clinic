import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { MESSAGE_ROLES, canManageLibrary, listLibraryItems } from '@/modules/messaging';
import LibraryBoard from '@/modules/messaging/components/LibraryBoard';

export default async function LibraryPage() {
  const user = await requirePageRole(MESSAGE_ROLES);
  const t = await getTranslations('messages');
  const [items] = await Promise.all([listLibraryItems({ includeArchived: false })]);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{t('libraryTitle')}</h1>
          <p className="text-sm text-slate-500">{t('librarySubtitle')}</p>
        </div>
        <div className="flex gap-2">
          <Link href="/messages/new" className="btn-primary">
            {t('newMessageLink')}
          </Link>
          <Link href="/messages" className="btn-secondary">
            {t('backToMessages')}
          </Link>
        </div>
      </div>
      <LibraryBoard items={items} canManage={canManageLibrary(user.role)} />
    </div>
  );
}
