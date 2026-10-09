import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { PATIENT_ROLES, PatientForm } from '@/modules/patients';

export default async function NewPatientPage() {
  await requirePageRole(PATIENT_ROLES);
  const t = await getTranslations('patients');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('newPatient')}</h1>
      <PatientForm mode="create" route="/patients" />
    </div>
  );
}
