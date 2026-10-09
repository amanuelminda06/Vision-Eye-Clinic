import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { MESSAGE_OWNER_ROLES } from '@/modules/messaging';
import { listTemplates } from '@/modules/messaging/queries';
import TemplateEditor from '@/modules/messaging/components/TemplateEditor';

export default async function MessageTemplatesPage() {
  await requirePageRole(MESSAGE_OWNER_ROLES);
  const t = await getTranslations('messages');
  const templates = await listTemplates();

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('templatesTitle')}</h1>
        <Link href="/messages" className="btn-secondary">
          {t('backToOutbox')}
        </Link>
      </div>
      <TemplateEditor templates={templates} />
    </div>
  );
}
