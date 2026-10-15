import { countPatientSales } from '@/modules/sales';
import { countPatientAppointments } from '@/modules/appointments';
import { countPatientMessages } from '@/modules/messaging';
import { countPatientSickLeaves } from './sick-leave';

export type PatientTabId = 'overview' | 'sales' | 'appointments' | 'messages' | 'sick';

export const PATIENT_TABS: readonly PatientTabId[] = [
  'overview',
  'sales',
  'appointments',
  'messages',
  'sick',
];

/** Accepts a `?tab=` value, falling back to the overview. */
export function tabFrom(value: string | undefined): PatientTabId {
  return (PATIENT_TABS as readonly string[]).includes(value ?? '')
    ? (value as PatientTabId)
    : 'overview';
}

/**
 * Detail-page tabs. Later slices (messages) replace their placeholders below —
 * the detail page already calls them, so no page changes are needed.
 */
export type PatientTabPayload = {
  /** false → the page shows the "coming later" placeholder. */
  ready: boolean;
  /** Number of records. */
  count: number;
};

export type PatientTabLoader = (patientId: string) => Promise<PatientTabPayload>;

const salesLoader: PatientTabLoader = async (patientId) => ({
  ready: true,
  count: await countPatientSales(patientId),
});

const appointmentsLoader: PatientTabLoader = async (patientId) => ({
  ready: true,
  count: await countPatientAppointments(patientId),
});

const messagesLoader: PatientTabLoader = async (patientId) => ({
  ready: true,
  count: await countPatientMessages(patientId),
});

const sickLoader: PatientTabLoader = async (patientId) => ({
  ready: true,
  count: await countPatientSickLeaves(patientId),
});

export const PATIENT_TAB_LOADERS: Record<PatientTabId, PatientTabLoader> = {
  overview: async () => ({ ready: true, count: 0 }),
  sales: salesLoader,
  appointments: appointmentsLoader,
  messages: messagesLoader,
  sick: sickLoader,
};

export async function loadPatientTab(
  tab: PatientTabId,
  patientId: string,
): Promise<PatientTabPayload> {
  return PATIENT_TAB_LOADERS[tab](patientId);
}
