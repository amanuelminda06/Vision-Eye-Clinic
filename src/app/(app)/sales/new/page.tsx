import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { getLocale } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { listActiveServices } from '@/modules/services';
import { NewSaleForm, SALES_ROLES, listDoctorOptions, resolveSalePrefill } from '@/modules/sales';

type SearchParams = Promise<{
  patientId?: string;
  doctorId?: string;
  serviceId?: string;
  appointmentId?: string;
}>;

export default async function NewSalePage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(SALES_ROLES);
  const t = await getTranslations('sales');
  const tc = await getTranslations('common');
  const locale = await getLocale();

  const sp = await searchParams;
  const [services, doctors, prefill] = await Promise.all([
    listActiveServices(),
    listDoctorOptions(),
    resolveSalePrefill({
      patientId: sp.patientId,
      doctorId: sp.doctorId,
      serviceId: sp.serviceId,
      appointmentId: sp.appointmentId,
    }),
  ]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('newSale')}</h1>
        <Link href="/sales" className="btn-secondary">
          {tc('back')}
        </Link>
      </div>

      <NewSaleForm
        services={services.map((service) => ({
          id: service.id,
          name: service.name,
          nameAm: service.nameAm,
          currentPrice: service.currentPrice,
        }))}
        doctors={doctors}
        locale={locale}
        prefill={prefill}
      />
    </div>
  );
}
