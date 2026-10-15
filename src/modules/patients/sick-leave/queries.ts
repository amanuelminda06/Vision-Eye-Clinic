import { prisma } from '@/lib/prisma';

export type PatientSickLeaveRow = {
  id: string;
  patientId: string;
  startDate: Date;
  endDate: Date;
  reason: string | null;
  note: string | null;
  recordedById: string;
  recordedByName: string | null;
  createdAt: Date;
  updatedAt: Date;
};

export type PatientSickLeaveFormValues = {
  startDate: string;
  endDate: string;
  reason: string;
  note: string;
};

/** All sick-leave records for a patient, newest first. */
export async function listPatientSickLeaves(patientId: string): Promise<PatientSickLeaveRow[]> {
  const rows = await prisma.patientSickLeave.findMany({
    where: { patientId },
    include: { recordedBy: { select: { id: true, name: true } } },
    orderBy: [{ startDate: 'desc' }, { createdAt: 'desc' }],
  });
  return rows.map((row) => ({
    id: row.id,
    patientId: row.patientId,
    startDate: row.startDate,
    endDate: row.endDate,
    reason: row.reason,
    note: row.note,
    recordedById: row.recordedById,
    recordedByName: row.recordedBy.name,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  }));
}

/** One record by id, or null. */
export async function getPatientSickLeave(id: string): Promise<PatientSickLeaveRow | null> {
  const row = await prisma.patientSickLeave.findUnique({
    where: { id },
    include: { recordedBy: { select: { id: true, name: true } } },
  });
  if (!row) return null;
  return {
    id: row.id,
    patientId: row.patientId,
    startDate: row.startDate,
    endDate: row.endDate,
    reason: row.reason,
    note: row.note,
    recordedById: row.recordedById,
    recordedByName: row.recordedBy.name,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  };
}

/** Number of sick-leave records for a patient (used for the tab badge). */
export async function countPatientSickLeaves(patientId: string): Promise<number> {
  return prisma.patientSickLeave.count({ where: { patientId } });
}
