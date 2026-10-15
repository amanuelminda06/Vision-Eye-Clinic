import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { PATIENT_ROLES, PatientForm, getPatient } from '@/modules/patients';
import { notFound } from 'next/navigation';

type Params = Promise<{ id: string }>;

export default async function EditPatientPage({ params }: { params: Params }) {
  await requirePageRole(PATIENT_ROLES);
  const { id } = await params;
  const patient = await getPatient(id);
  if (!patient) notFound();

  const t = await getTranslations('patients');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('editPatient')}</h1>
      <PatientForm
        mode="edit"
        route={`/patients/${patient.id}`}
        patient={{
          id: patient.id,
          fullName: patient.fullName,
          phoneE164: patient.phoneE164,
          sex: patient.sex,
          birthDate: patient.birthDate ? patient.birthDate.toISOString().slice(0, 10) : null,
          preferredLanguage: patient.preferredLanguage,
          notesNonClinical: patient.notesNonClinical,
          consentSms: patient.consentSms,
          idPhotoUrl: patient.idPhotoUrl,
        }}
      />
    </div>
  );
}
