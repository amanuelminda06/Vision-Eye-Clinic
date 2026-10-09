import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { SERVICES_ROLES, ServiceForm } from '@/modules/services';

export default async function NewServicePage() {
  await requirePageRole(SERVICES_ROLES);
  const t = await getTranslations('services');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('newService')}</h1>
      <ServiceForm mode="create" route="/dashboard/services" />
    </div>
  );
}
