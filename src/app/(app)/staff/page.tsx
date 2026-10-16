import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { listStaff, StaffRowActions, STAFF_PAGE_SIZE } from '@/modules/staff';

type SearchParams = Promise<{
  q?: string;
  status?: string;
  page?: string;
}>;

function buildQuery(filters: { q?: string; status?: string; page?: number }): string {
  const params = new URLSearchParams();
  if (filters.q) params.set('q', filters.q);
  if (filters.status) params.set('status', filters.status);
  if (filters.page && filters.page > 1) params.set('page', String(filters.page));
  const query = params.toString();
  return query ? `?${query}` : '';
}

export default async function StaffPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(['OWNER']);
  const sp = await searchParams;
  const t = await getTranslations('staff');
  const tc = await getTranslations('common');

  const status = sp.status === 'true' || sp.status === 'false' ? sp.status : '';
  const q = sp.q?.slice(0, 100) ?? '';
  const page = Number(sp.page) || 1;

  const { rows, total, page: currentPage, pageCount } = await listStaff({ q, status, page });

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <Link href="/staff/new" className="btn-primary">
          {t('newStaff')}
        </Link>
      </div>

      <form method="get" className="card grid grid-cols-1 sm:grid-cols-4 gap-2 p-3">
        <div className="col-span-1 sm:col-span-2">
          <label className="label text-xs" htmlFor="q">
            {tc('search')}
          </label>
          <input
            className="input-field text-sm"
            id="q"
            name="q"
            type="search"
            defaultValue={q}
            placeholder={t('searchPlaceholder')}
          />
        </div>
        <div>
          <label className="label text-xs" htmlFor="status">
            {tc('status')}
          </label>
          <select className="input-field text-sm" id="status" name="status" defaultValue={status}>
            <option value="">{tc('all')}</option>
            <option value="true">{tc('active')}</option>
            <option value="false">{tc('inactive')}</option>
          </select>
        </div>
        <div className="flex items-end">
          <button type="submit" className="btn-secondary text-sm px-3 py-1.5">
            {tc('filter')}
          </button>
          <Link href="/staff" className="ml-2 pb-1 text-xs text-slate-500 hover:text-slate-800">
            {tc('clear')}
          </Link>
        </div>
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('fullName')}</th>
              <th className="px-4 py-3 font-medium">{t('jobTitle')}</th>
              <th className="px-4 py-3 font-medium">{t('specialty')}</th>
              <th className="px-4 py-3 font-medium">{t('phone')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                  {q || status ? tc('noResults') : tc('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      {row.photoUrl ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          src={row.photoUrl}
                          alt=""
                          className="h-9 w-9 rounded-full border border-slate-200 object-cover"
                        />
                      ) : (
                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">
                          {row.fullName.slice(0, 1).toUpperCase()}
                        </span>
                      )}
                      <Link
                        href={`/staff/${row.id}`}
                        className="font-medium text-slate-900 hover:underline"
                      >
                        {row.fullName}
                      </Link>
                      {row.isDoctor ? (
                        <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                          {t('isDoctor')}
                        </span>
                      ) : null}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.jobTitle}</td>
                  <td className="px-4 py-3 text-slate-600">{row.specialty ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{row.phone ?? '—'}</td>
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
                  <td className="px-4 py-3">
                    <StaffRowActions staffId={row.id} active={row.active} />
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
            from: total === 0 ? 0 : (currentPage - 1) * STAFF_PAGE_SIZE + 1,
            to: Math.min(currentPage * STAFF_PAGE_SIZE, total),
            total,
          })}
        </span>
        <div className="flex items-center gap-2">
          {currentPage > 1 ? (
            <Link
              href={`/staff${buildQuery({ q, status, page: currentPage - 1 })}`}
              className="btn-secondary"
            >
              {tc('previous')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('previous')}</span>
          )}
          {currentPage < pageCount ? (
            <Link
              href={`/staff${buildQuery({ q, status, page: currentPage + 1 })}`}
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
