import Link from 'next/link';
import { cache } from 'react';
import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { prisma } from '@/lib/prisma';
import { clientIp } from '@/lib/audit';
import { formatInTz } from '@/lib/datetime';
import { formatPhoneDisplay } from '@/lib/phone';
import { getSettingsMap, timezoneFrom, settingsFromMap } from '@/modules/settings';
import { PatientSalesList } from '@/modules/sales';
import PatientAppointmentsList from '@/modules/appointments/components/PatientAppointmentsList';
import PatientMessagesList from '@/modules/messaging/components/PatientMessagesList';
import {
  PATIENT_ROLES,
  getPatient,
  listAllTags,
  tabFrom,
  loadPatientTab,
  PATIENT_TABS,
  TagsEditor,
  StopMessagesCard,
  PatientStatusButton,
  PatientIdPhotoCapture,
  listPatientSickLeaves,
  PatientSickLeaveTab,
} from '@/modules/patients';

type Params = Promise<{ id: string }>;
type SearchParams = Promise<{ tab?: string }>;

const TAB_KEYS = {
  overview: 'tabOverview',
  sales: 'tabSales',
  appointments: 'tabAppointments',
  messages: 'tabMessages',
  sick: 'tabSick',
} as const;

/**
 * Opening the detail page is audited (action VIEW). The page body runs twice
 * per request (HTML + RSC pass), so the two writers serialize on a Postgres
 * advisory lock and a VIEW by the same user is only recorded once a minute.
 */
const VIEW_DEDUPE_MS = 60_000;

const logPatientView = cache(async (patientId: string, userId: string) => {
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT pg_advisory_xact_lock(hashtext(${`view:${patientId}:${userId}`}))`;
      const recent = await tx.auditLog.findFirst({
        where: {
          entity: 'Patient',
          entityId: patientId,
          action: 'VIEW',
          userId,
          createdAt: { gte: new Date(Date.now() - VIEW_DEDUPE_MS) },
        },
        select: { id: true },
      });
      if (recent) return;
      await tx.auditLog.create({
        data: {
          userId,
          entity: 'Patient',
          entityId: patientId,
          action: 'VIEW',
          ip: await clientIp(),
        },
      });
    });
  } catch (err) {
    // Audit failures never block the page (same rule as writeAudit).
    console.error('[patients] failed to write VIEW audit:', err);
  }
});

export default async function PatientDetailPage({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: SearchParams;
}) {
  const actor = await requirePageRole(PATIENT_ROLES);
  const { id } = await params;
  const sp = await searchParams;
  const patient = await getPatient(id);
  if (!patient) notFound();

  // Audit rule: opening the detail page is itself recorded (action VIEW).
  await logPatientView(patient.id, actor.id);

  const t = await getTranslations('patients');
  const tc = await getTranslations('common');
  const tab = tabFrom(sp.tab);
  const payload = await loadPatientTab(tab, patient.id);

  const [settings, allTags, sickRows] = await Promise.all([
    getSettingsMap(),
    listAllTags(),
    listPatientSickLeaves(patient.id),
  ]);
  const tz = timezoneFrom(settings);
  const clinic = settingsFromMap(settings);

  const sexLabel =
    patient.sex === 'MALE'
      ? t('sexMale')
      : patient.sex === 'FEMALE'
        ? t('sexFemale')
        : patient.sex === 'OTHER'
          ? t('sexOther')
          : '—';

  const tabLink = (id: (typeof PATIENT_TABS)[number]) => (
    <Link
      key={id}
      href={`/patients/${patient.id}?tab=${id}`}
      prefetch={false}
      className={
        'border-b-2 px-3 py-2 text-sm font-medium ' +
        (tab === id
          ? 'border-slate-900 text-slate-900'
          : 'border-transparent text-slate-500 hover:text-slate-800')
      }
    >
      {t(TAB_KEYS[id] as never)}
      {id !== 'overview' && payload.count > 0 && tab === id ? (
        <span className="ml-1 rounded-full bg-slate-100 px-1.5 text-xs">{payload.count}</span>
      ) : null}
    </Link>
  );

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold text-slate-900">{patient.fullName}</h1>
          <span
            className={
              'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
              (patient.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')
            }
          >
            {patient.active ? tc('active') : tc('inactive')}
          </span>
          {patient.doNotMessage ? (
            <span className="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
              {t('dnmYes')}
            </span>
          ) : null}
        </div>
        <div className="flex items-center gap-2">
          <Link href="/patients" className="btn-secondary">
            {t('backToList')}
          </Link>
          <Link href={`/patients/${patient.id}/edit`} className="btn-primary">
            {tc('edit')}
          </Link>
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <div className="card space-y-3 p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <a
                href={`tel:${patient.phoneE164}`}
                className="text-lg font-medium text-slate-900 hover:underline"
              >
                {formatPhoneDisplay(patient.phoneE164)}
              </a>
              <span className="text-xs text-slate-500">{t('tapToCall')}</span>
            </div>
            <dl className="grid gap-3 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-xs uppercase text-slate-500">{t('sex')}</dt>
                <dd className="text-slate-800">{sexLabel}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase text-slate-500">{t('birthDate')}</dt>
                <dd className="text-slate-800">
                  {patient.birthDate ? patient.birthDate.toISOString().slice(0, 10) : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs uppercase text-slate-500">{t('idNo')}</dt>
                <dd className="text-slate-800">{patient.idNo ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase text-slate-500">{tc('language')}</dt>
                <dd className="text-slate-800">
                  {patient.preferredLanguage === 'am' ? t('langAm') : t('langEn')}
                </dd>
              </div>
            </dl>
            <div className="text-xs text-slate-500">
              <p>
                {t('createdMeta', {
                  name: patient.createdByName ?? '—',
                  date: formatInTz(patient.createdAt, tz),
                })}
              </p>
              <p>{t('updatedMeta', { date: formatInTz(patient.updatedAt, tz) })}</p>
            </div>
          </div>

          <PatientIdPhotoCapture patientId={patient.id} existingUrl={patient.idPhotoUrl} />

          <div className="border-b border-slate-200">
            <nav className="-mb-px flex flex-wrap">{PATIENT_TABS.map(tabLink)}</nav>
          </div>

          {tab === 'overview' ? (
            <div className="card space-y-4 p-5">
              <div>
                <h2 className="text-sm font-semibold text-slate-800">{t('notes')}</h2>
                <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">
                  {patient.notesNonClinical || '—'}
                </p>
                <p className="mt-2 text-xs text-amber-700">{t('notesHint')}</p>
              </div>
            </div>
          ) : tab === 'sales' ? (
            <div className="card p-5">
              <PatientSalesList patientId={patient.id} timeZone={tz} currency={clinic.currency} />
            </div>
          ) : tab === 'appointments' ? (
            <PatientAppointmentsList patientId={patient.id} timeZone={tz} />
          ) : tab === 'sick' ? (
            <PatientSickLeaveTab
              rows={sickRows}
              timeZone={tz}
              route={`/patients/${patient.id}`}
              patientId={patient.id}
            />
          ) : (
            <div className="card p-5">
              <PatientMessagesList patientId={patient.id} timeZone={tz} />
            </div>
          )}
        </div>

        <div className="space-y-5">

          <TagsEditor
            patientId={patient.id}
            tags={patient.tags.map((link) => link.tag)}
            allTags={allTags}
          />

          <StopMessagesCard
            key={patient.doNotMessage ? 'stopped' : 'allowed'}
            patientId={patient.id}
            doNotMessage={patient.doNotMessage}
            reason={patient.doNotMessageReason}
          />

          <PatientStatusButton patientId={patient.id} active={patient.active} />
        </div>
      </div>
    </div>
  );
}
