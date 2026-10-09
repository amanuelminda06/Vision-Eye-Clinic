import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import ExportLinks from '@/components/ExportLinks';
import {
  listPatients,
  listTagsForFilter,
  sortFrom,
  dirFrom,
  PATIENTS_PAGE_SIZE,
  PATIENT_ROLES,
  type PatientListFilters,
  type PatientSort,
  type SortDir,
} from '@/modules/patients';

type SearchParams = Promise<{
  q?: string;
  tag?: string;
  consent?: string;
  dnm?: string;
  status?: string;
  sort?: string;
  dir?: string;
  page?: string;
}>;

function buildQuery(filters: PatientListFilters, patch: Partial<PatientListFilters> = {}): string {
  const merged = { ...filters, ...patch };
  const params = new URLSearchParams();
  if (merged.q) params.set('q', merged.q);
  if (merged.tag) params.set('tag', merged.tag);
  if (merged.consent) params.set('consent', merged.consent);
  if (merged.dnm) params.set('dnm', merged.dnm);
  if (merged.status) params.set('status', merged.status);
  if (merged.sort && merged.sort !== 'fullName') params.set('sort', merged.sort);
  if (merged.dir && merged.dir !== 'asc') params.set('dir', merged.dir);
  if (merged.page && merged.page > 1) params.set('page', String(merged.page));
  const query = params.toString();
  return query ? `?${query}` : '';
}

export default async function PatientsPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(PATIENT_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('patients');
  const tc = await getTranslations('common');

  const q = sp.q?.slice(0, 100) ?? '';
  const tag = sp.tag?.slice(0, 64) ?? '';
  const consent = sp.consent === 'true' || sp.consent === 'false' ? sp.consent : '';
  const dnm = sp.dnm === 'true' || sp.dnm === 'false' ? sp.dnm : '';
  const status = sp.status === 'true' || sp.status === 'false' ? sp.status : '';
  const sort = sortFrom(sp.sort);
  const dir = dirFrom(sp.dir);
  const page = Number(sp.page) || 1;

  const filters: PatientListFilters = { q, tag, consent, dnm, status, sort, dir, page };
  const [tags, result] = await Promise.all([listTagsForFilter(), listPatients(filters)]);
  const { rows, total, page: currentPage, pageCount } = result;

  const sortLink = (key: PatientSort, label: string) => {
    const nextDir: SortDir = sort === key && dir === 'asc' ? 'desc' : 'asc';
    return (
      <Link
        href={`/patients${buildQuery(filters, { sort: key, dir: nextDir, page: 1 })}`}
        className="hover:text-slate-900"
      >
        {label}
        {sort === key ? <span aria-hidden="true">{dir === 'asc' ? ' ↑' : ' ↓'}</span> : null}
      </Link>
    );
  };

  const sexLabel = (sex: string | null): string => {
    if (sex === 'MALE') return t('sexMale');
    if (sex === 'FEMALE') return t('sexFemale');
    if (sex === 'OTHER') return t('sexOther');
    return '—';
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <div className="flex items-center gap-2">
          <Link href="/patients/import" className="btn-secondary">
            {t('importCsv')}
          </Link>
          <Link href="/patients/new" className="btn-primary">
            {t('newPatient')}
          </Link>
        </div>
      </div>

      <form method="get" className="card flex flex-wrap items-end gap-3 p-4">
        <div className="min-w-52 grow">
          <label className="label" htmlFor="q">
            {tc('search')}
          </label>
          <input
            className="input-field"
            id="q"
            name="q"
            type="search"
            defaultValue={q}
            placeholder={t('searchPlaceholder')}
          />
        </div>
        <div>
          <label className="label" htmlFor="tag">
            {t('tagFilter')}
          </label>
          <select className="input-field" id="tag" name="tag" defaultValue={tag}>
            <option value="">{tc('all')}</option>
            {tags.map((item) => (
              <option key={item.id} value={item.name}>
                {item.name} ({item.patientCount})
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="label" htmlFor="consent">
            {t('consentFilter')}
          </label>
          <select className="input-field" id="consent" name="consent" defaultValue={consent}>
            <option value="">{tc('all')}</option>
            <option value="true">{t('consentYes')}</option>
            <option value="false">{t('consentNo')}</option>
          </select>
        </div>
        <div>
          <label className="label" htmlFor="dnm">
            {t('dnmFilter')}
          </label>
          <select className="input-field" id="dnm" name="dnm" defaultValue={dnm}>
            <option value="">{tc('all')}</option>
            <option value="true">{t('dnmYes')}</option>
            <option value="false">{t('dnmNo')}</option>
          </select>
        </div>
        <div>
          <label className="label" htmlFor="status">
            {t('statusFilter')}
          </label>
          <select className="input-field" id="status" name="status" defaultValue={status}>
            <option value="">{tc('all')}</option>
            <option value="true">{tc('active')}</option>
            <option value="false">{tc('inactive')}</option>
          </select>
        </div>
        <button type="submit" className="btn-secondary">
          {tc('filter')}
        </button>
        <Link href="/patients" className="pb-2 text-sm text-slate-500 hover:text-slate-800">
          {tc('clear')}
        </Link>
        <ExportLinks base="/api/patients/export" query={buildQuery(filters, { page: 1 })} />
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{sortLink('fullName', t('fullName'))}</th>
              <th className="px-4 py-3 font-medium">{t('phone')}</th>
              <th className="px-4 py-3 font-medium">{t('sex')}</th>
              <th className="px-4 py-3 font-medium">{sortLink('birthDate', t('birthDate'))}</th>
              <th className="px-4 py-3 font-medium">{t('tagsTitle')}</th>
              <th className="px-4 py-3 font-medium">{t('consentFilter')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 font-medium">{sortLink('createdAt', t('createdCol'))}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-10 text-center text-slate-500">
                  {q || tag || consent || dnm || status ? tc('noResults') : tc('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3">
                    <Link
                      href={`/patients/${row.id}`}
                      prefetch={false}
                      className="font-medium text-slate-900 hover:underline"
                    >
                      {row.fullName}
                    </Link>
                    {row.doNotMessage ? (
                      <span className="ml-2 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
                        {t('dnmYes')}
                      </span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    <a href={`tel:${row.phoneE164}`} className="hover:text-slate-900">
                      {row.phoneE164}
                    </a>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{sexLabel(row.sex)}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {row.birthDate ? row.birthDate.toISOString().slice(0, 10) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-wrap gap-1">
                      {row.tags.map((link) => (
                        <span
                          key={link.tagId}
                          className="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                          style={{ backgroundColor: link.tag.color }}
                        >
                          {link.tag.name}
                        </span>
                      ))}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                        (row.consentSms
                          ? 'bg-emerald-50 text-emerald-700'
                          : 'bg-slate-100 text-slate-500')
                      }
                    >
                      {row.consentSms ? t('consentYes') : t('consentNo')}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                        (row.active
                          ? 'bg-emerald-50 text-emerald-700'
                          : 'bg-slate-100 text-slate-500')
                      }
                    >
                      {row.active ? tc('active') : tc('inactive')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {row.createdAt.toISOString().slice(0, 10)}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
        <span>
          {tc('showing', {
            from: total === 0 ? 0 : (currentPage - 1) * PATIENTS_PAGE_SIZE + 1,
            to: Math.min(currentPage * PATIENTS_PAGE_SIZE, total),
            total,
          })}
        </span>
        <div className="flex items-center gap-2">
          {currentPage > 1 ? (
            <Link
              href={`/patients${buildQuery(filters, { page: currentPage - 1 })}`}
              className="btn-secondary"
            >
              {tc('previous')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('previous')}</span>
          )}
          {currentPage < pageCount ? (
            <Link
              href={`/patients${buildQuery(filters, { page: currentPage + 1 })}`}
              className="btn-secondary"
            >
              {tc('next')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('next')}</span>
          )}
        </div>
      </div>
    </div>
  );
}
