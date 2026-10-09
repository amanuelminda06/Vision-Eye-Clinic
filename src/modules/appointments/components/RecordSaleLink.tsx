'use client';

import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { salePrefillQuery } from '@/modules/sales/prefill-query';

type Props = {
  patientId: string;
  doctorId?: string;
  serviceId?: string;
  appointmentId: string;
};

/** "Record sale" — opens the new-sale screen prefilled from this appointment. */
export default function RecordSaleLink({ patientId, doctorId, serviceId, appointmentId }: Props) {
  const t = useTranslations('appointments');
  const href = `/sales/new${salePrefillQuery({
    patientId,
    doctorId,
    serviceId,
    appointmentId,
  })}`;

  return (
    <Link href={href} className="btn-secondary" prefetch={false}>
      {t('recordSale')}
    </Link>
  );
}
