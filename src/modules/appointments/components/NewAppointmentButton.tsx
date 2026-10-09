'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';
import AppointmentFormModal, {
  type AppointmentFormInitial,
  type AppointmentFormProps,
} from './AppointmentFormModal';

type Props = {
  doctors: AppointmentFormProps['doctors'];
  services: AppointmentFormProps['services'];
  initial?: AppointmentFormInitial | null;
};

/** "New appointment" trigger (and the modal it owns). */
export default function NewAppointmentButton({ doctors, services, initial = null }: Props) {
  const t = useTranslations('appointments');
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className="btn-primary" onClick={() => setOpen(true)}>
        {t('newAppointment')}
      </button>
      {open ? (
        <AppointmentFormModal
          open={open}
          onClose={() => setOpen(false)}
          onDone={() => setOpen(false)}
          doctors={doctors}
          services={services}
          initial={initial}
        />
      ) : null}
    </>
  );
}
