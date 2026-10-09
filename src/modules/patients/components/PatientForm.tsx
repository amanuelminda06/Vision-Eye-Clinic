'use client';

import Link from 'next/link';
import { useActionState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import {
  createPatientAction,
  updatePatientAction,
  type PatientFormSubmit,
} from '@/modules/patients/actions';
import { formatPhoneDisplay } from '@/lib/phone';
import type { ActionResult } from '@/lib/action';

type Values = {
  fullName: string;
  phoneE164: string;
  sex: string | null;
  birthDate: string | null;
  preferredLanguage: string;
  notesNonClinical: string | null;
  consentSms: boolean;
};

type Props = {
  mode: 'create' | 'edit';
  patient?: Values & { id: string };
  route: string;
};

export default function PatientForm({ mode, patient, route }: Props) {
  const router = useRouter();
  const t = useTranslations('patients');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');
  const action = mode === 'create' ? createPatientAction : updatePatientAction;
  const [state, formAction, pending] = useActionState<
    ActionResult<PatientFormSubmit> | null,
    FormData
  >(action, null);

  const failed = !!state && !state.ok;
  const fields = failed ? state.fields : undefined;
  const fieldError = (key: string): string | undefined =>
    fields?.[key] ? tv(fields[key] as never) : undefined;

  const duplicate = failed ? state.existing : undefined;
  const confirmed = !!state?.ok && !!state.data && 'needsConfirm' in state.data;

  useEffect(() => {
    if (!state?.ok) return;
    if (state.data && 'id' in state.data) {
      router.push(`/patients/${state.data.id}`);
      router.refresh();
    }
  }, [state, router]);

  return (
    <form action={formAction} className="card max-w-2xl space-y-4 p-6">
      {mode === 'edit' ? <input type="hidden" name="id" value={patient?.id} /> : null}
      {confirmed ? <input type="hidden" name="confirm" value="true" /> : null}

      <div>
        <label className="label" htmlFor="fullName">
          {t('fullName')}
        </label>
        <input
          className="input-field"
          id="fullName"
          name="fullName"
          type="text"
          required
          autoComplete="off"
          defaultValue={patient?.fullName ?? ''}
          disabled={pending}
          aria-invalid={!!fieldError('fullName')}
        />
        {fieldError('fullName') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('fullName')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="label" htmlFor="phone">
          {t('phone')}
        </label>
        <input
          className="input-field"
          id="phone"
          name="phone"
          type="tel"
          inputMode="tel"
          placeholder="0912345678"
          required
          autoComplete="off"
          defaultValue={patient ? formatPhoneDisplay(patient.phoneE164) : ''}
          disabled={pending}
          aria-invalid={!!fieldError('phone')}
        />
        <p className="mt-1 text-xs text-slate-500">{t('phoneHint')}</p>
        {fieldError('phone') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('phone')}
          </p>
        ) : null}
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div>
          <label className="label" htmlFor="sex">
            {t('sex')}
          </label>
          <select
            className="input-field"
            id="sex"
            name="sex"
            defaultValue={patient?.sex ?? ''}
            disabled={pending}
          >
            <option value="">{t('sexUnknown')}</option>
            <option value="MALE">{t('sexMale')}</option>
            <option value="FEMALE">{t('sexFemale')}</option>
            <option value="OTHER">{t('sexOther')}</option>
          </select>
          {fieldError('sex') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('sex')}
            </p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor="birthDate">
            {t('birthDate')}
          </label>
          <input
            className="input-field"
            id="birthDate"
            name="birthDate"
            type="date"
            max={new Date().toISOString().slice(0, 10)}
            defaultValue={patient?.birthDate ?? ''}
            disabled={pending}
            aria-invalid={!!fieldError('birthDate')}
          />
          {fieldError('birthDate') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('birthDate')}
            </p>
          ) : null}
        </div>

        <div>
          <label className="label" htmlFor="preferredLanguage">
            {tc('language')}
          </label>
          <select
            className="input-field"
            id="preferredLanguage"
            name="preferredLanguage"
            defaultValue={patient?.preferredLanguage ?? ''}
            disabled={pending}
          >
            <option value="">{t('languageDefault')}</option>
            <option value="en">{t('langEn')}</option>
            <option value="am">{t('langAm')}</option>
          </select>
          {fieldError('preferredLanguage') ? (
            <p role="alert" className="mt-1 text-xs font-medium text-red-600">
              {fieldError('preferredLanguage')}
            </p>
          ) : null}
        </div>
      </div>

      <div>
        <label className="label" htmlFor="notesNonClinical">
          {t('notes')}
        </label>
        <textarea
          className="input-field min-h-24"
          id="notesNonClinical"
          name="notesNonClinical"
          rows={3}
          defaultValue={patient?.notesNonClinical ?? ''}
          disabled={pending}
        />
        <p className="mt-1 text-xs text-amber-700">{t('notesHint')}</p>
        {fieldError('notesNonClinical') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('notesNonClinical')}
          </p>
        ) : null}
      </div>

      <div>
        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            name="consentSms"
            value="true"
            defaultChecked={patient?.consentSms ?? false}
            disabled={pending}
            className="mt-0.5 h-4 w-4 rounded border-slate-300"
          />
          <span>
            {t('consentLabel')}
            <span className="mt-0.5 block text-xs text-slate-500">{t('consentHint')}</span>
          </span>
        </label>
        {fieldError('consentSms') ? (
          <p role="alert" className="mt-1 text-xs font-medium text-red-600">
            {fieldError('consentSms')}
          </p>
        ) : null}
      </div>

      {confirmed && state?.ok && state.data && 'similar' in state.data ? (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="font-medium">{t('similarTitle')}</p>
          <p className="mt-1">{t('similarBody')}</p>
          <ul className="mt-2 list-inside list-disc">
            {state.data.similar.map((person) => (
              <li key={person.id}>
                <Link href={`/patients/${person.id}`} className="font-medium hover:underline">
                  {person.fullName}
                </Link>
              </li>
            ))}
          </ul>
          <p className="mt-2">{t('similarConfirmHint')}</p>
        </div>
      ) : null}

      {duplicate ? (
        <div
          role="alert"
          className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
        >
          <p>{tc('phoneTaken')}</p>
          <Link
            href={`/patients/${duplicate.id}`}
            className="mt-1 inline-block font-medium hover:underline"
          >
            {t('openExisting', { name: duplicate.fullName })}
          </Link>
        </div>
      ) : null}

      <ActionError error={failed && !duplicate ? state.error : undefined} />

      <div className="flex items-center gap-3 pt-2">
        <button type="submit" className="btn-primary" disabled={pending}>
          {pending ? tc('saving') : mode === 'create' ? t('create') : t('update')}
        </button>
        <Link href={route} className="btn-secondary">
          {tc('cancel')}
        </Link>
      </div>
    </form>
  );
}
