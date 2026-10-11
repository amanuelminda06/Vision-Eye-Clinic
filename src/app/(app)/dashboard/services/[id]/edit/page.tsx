import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  getService,
  PriceHistoryModal,
  SERVICES_ROLES,
  ServiceForm,
  ServiceStatusButton,
} from '@/modules/services';

type Params = Promise<{ id: string }>;

export default async function EditServicePage({ params }: { params: Params }) {
  await requirePageRole(SERVICES_ROLES);
  const { id } = await params;
  const service = await getService(id);
  if (!service) notFound();

  const t = await getTranslations('services');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('editService')}</h1>
        <div className="flex items-center gap-2">
          <PriceHistoryModal serviceId={service.id} serviceName={service.name} tz={tz} />
          <Link href="/services" className="btn-secondary">
            {t('backToList')}
          </Link>
        </div>
      </div>

      <ServiceForm
        mode="edit"
        route="/dashboard/services"
        service={{
          id: service.id,
          nameEn: service.nameEn,
          nameAm: service.nameAm,
          category: service.category,
          currentPrice: service.currentPrice,
          isPublic: service.isPublic,
          publicDescriptionEn: service.publicDescriptionEn,
          publicDescriptionAm: service.publicDescriptionAm,
          receiptLabelEn: service.receiptLabelEn,
          receiptLabelAm: service.receiptLabelAm,
          displayOrder: service.displayOrder,
          imageUrl: service.imageUrl,
        }}
      />

      <div className="max-w-2xl">
        <ServiceStatusButton serviceId={service.id} active={service.active} />
      </div>
    </div>
  );
}
