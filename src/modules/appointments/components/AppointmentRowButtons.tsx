'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';
import AppointmentFormModal, {
  type AppointmentFormInitial,
  type AppointmentFormProps,
} from './AppointmentFormModal';
import AppointmentStatusActions from './AppointmentStatusActions';
import CreateFollowUpDialog from './CreateFollowUpDialog';
import RecordSaleLink from './RecordSaleLink';

export type RowAppointment = {
  id: string;
  patientId: string;
  patientName: string;
  doctorId: string;
  serviceId: string | null;
  dateKey: string;
  startTime: string;
  durationMin: number;
  status: string;
  notes: string | null;
};

type Props = {
  appointment: RowAppointment;
  canOverride: boolean;
  doctors: AppointmentFormProps['doctors'];
  services: AppointmentFormProps['services'];
};

/** Per-row controls: edit modal, status moves, follow-up + "record sale" CTAs. */
export default function AppointmentRowButtons({
  appointment,
  canOverride,
  doctors,
  services,
}: Props) {
  const t = useTranslations('appointments');
  const [editOpen, setEditOpen] = useState(false);
  const [followOpen, setFollowOpen] = useState(false);

  const initial: AppointmentFormInitial = {
    id: appointment.id,
    patient: { id: appointment.patientId, fullName: appointment.patientName },
    doctorId: appointment.doctorId,
    serviceId: appointment.serviceId ?? undefined,
    dateKey: appointment.dateKey,
    startTime: appointment.startTime,
    durationMin: appointment.durationMin,
    notes: appointment.notes ?? undefined,
  };

  return (
    <div className="flex flex-wrap items-center gap-2">
      {appointment.status === 'CANCELLED' || appointment.status === 'NO_SHOW' ? null : (
        <button type="button" className="btn-secondary" onClick={() => setEditOpen(true)}>
          {t('edit')}
        </button>
      )}

      <AppointmentStatusActions
        appointmentId={appointment.id}
        currentStatus={appointment.status}
        canOverride={canOverride}
      />

      {appointment.status === 'COMPLETED' ? (
        <>
          <button type="button" className="btn-secondary" onClick={() => setFollowOpen(true)}>
            {t('createFollowUp')}
          </button>
          <RecordSaleLink
            patientId={appointment.patientId}
            doctorId={appointment.doctorId}
            serviceId={appointment.serviceId ?? undefined}
            appointmentId={appointment.id}
          />
        </>
      ) : null}

      {editOpen ? (
        <AppointmentFormModal
          open={editOpen}
          onClose={() => setEditOpen(false)}
          onDone={() => setEditOpen(false)}
          doctors={doctors}
          services={services}
          initial={initial}
        />
      ) : null}

      {followOpen ? (
        <CreateFollowUpDialog
          open={followOpen}
          onClose={() => setFollowOpen(false)}
          patientId={appointment.patientId}
          appointmentId={appointment.id}
        />
      ) : null}
    </div>
  );
}
