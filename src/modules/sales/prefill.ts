import { prisma } from '@/lib/prisma';
import { salePrefillQuery, type SalePrefillQueryParams } from './prefill-query';

export { salePrefillQuery };
export type { SalePrefillQueryParams };

export type SalePrefillParams = SalePrefillQueryParams;

export type ResolvedSalePrefill = {
  patient: { id: string; fullName: string; phoneE164: string } | null;
  doctorId: string | null;
  serviceId: string | null;
  appointmentId: string | null;
};

/** Resolve & sanity-check the prefill search params (missing rows → null). */
export async function resolveSalePrefill(params: SalePrefillParams): Promise<ResolvedSalePrefill> {
  const [patient, doctor, service] =
    params.patientId || params.doctorId || params.serviceId
      ? await Promise.all([
          params.patientId
            ? prisma.patient.findUnique({
                where: { id: params.patientId },
                select: { id: true, fullName: true, phoneE164: true },
              })
            : Promise.resolve(null),
          params.doctorId
            ? prisma.staff.findUnique({
                where: { id: params.doctorId, active: true, isDoctor: true },
                select: { id: true },
              })
            : Promise.resolve(null),
          params.serviceId
            ? prisma.service.findUnique({
                where: { id: params.serviceId, active: true },
                select: { id: true },
              })
            : Promise.resolve(null),
        ])
      : [null, null, null];

  return {
    patient,
    doctorId: doctor?.id ?? null,
    serviceId: service?.id ?? null,
    appointmentId: params.appointmentId ?? null,
  };
}
