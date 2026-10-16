import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatInTz } from '@/lib/datetime';
import { listUsers, UserRowActions, USERS_PAGE_SIZE } from '@/modules/users';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';

type SearchParams = Promise<{
  q?: string;
  role?: string;
  status?: string;
  page?: string;
}>;

const ROLE_KEYS = {
  OWNER: 'roleOwner',
  RECEPTION: 'roleReception',
  STAFF: 'roleStaff',
} as const;

function buildQuery(filters: {
  q?: string;
  role?: string;
  status?: string;
  page?: number;
}): string {
  const params = new URLSearchParams();
  if (filters.q) params.set('q', filters.q);
  if (filters.role) params.set('role', filters.role);
  if (filters.status) params.set('status', filters.status);
  if (filters.page && filters.page > 1) params.set('page', String(filters.page));
  const query = params.toString();
  return query ? `?${query}` : '';
}

export default async function UsersPage({ searchParams }: { searchParams: SearchParams }) {
  const actor = await requirePageRole(['OWNER']);
  const sp = await searchParams;
  const t = await getTranslations('users');
  const tc = await getTranslations('common');

  const role = sp.role === 'OWNER' || sp.role === 'RECEPTION' || sp.role === 'STAFF' ? sp.role : '';
  const status = sp.status === 'true' || sp.status === 'false' ? sp.status : '';
  const q = sp.q?.slice(0, 100) ?? '';
  const page = Number(sp.page) || 1;

  const [settings, result] = await Promise.all([
    getSettingsMap(),
    listUsers({ q, role, active: status, page }),
  ]);
  const tz = timezoneFrom(settings);
  const { rows, total, page: currentPage, pageCount } = result;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <Link href="/users/new" className="btn-primary">
          {t('newUser')}
        </Link>
      </div>

      <form method="get" className="card grid grid-cols-1 sm:grid-cols-3 gap-2 p-3">
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
          <label className="label text-xs" htmlFor="role">
            {t('roleFilter')}
          </label>
          <select className="input-field text-sm" id="role" name="role" defaultValue={role}>
            <option value="">{tc('all')}</option>
            <option value="OWNER">{tc('roleOwner')}</option>
            <option value="RECEPTION">{tc('roleReception')}</option>
            <option value="STAFF">{tc('roleStaff')}</option>
          </select>
        </div>
        <div>
          <label className="label text-xs" htmlFor="status">
            {t('statusFilter')}
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
          <Link href="/users" className="ml-2 pb-1 text-xs text-slate-500 hover:text-slate-800">
            {tc('clear')}
          </Link>
        </div>
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{tc('name')}</th>
              <th className="px-4 py-3 font-medium">{tc('email')}</th>
              <th className="px-4 py-3 font-medium">{tc('role')}</th>
              <th className="px-4 py-3 font-medium">{t('staffLink')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 font-medium">{t('lastLogin')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                  {q || role || status ? tc('noResults') : tc('empty')}
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
                      href={`/users/${row.id}`}
                      className="font-medium text-slate-900 hover:underline"
                    >
                      {row.name}
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.email}</td>
                  <td className="px-4 py-3 text-slate-600">{tc(ROLE_KEYS[row.role] as never)}</td>
                  <td className="px-4 py-3 text-slate-600">{row.staffName ?? t('noStaff')}</td>
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
                    {row.lastLoginAt ? formatInTz(row.lastLoginAt, tz) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <UserRowActions
                      userId={row.id}
                      active={row.active}
                      isSelf={row.id === actor.id}
                    />
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
            from: total === 0 ? 0 : (currentPage - 1) * USERS_PAGE_SIZE + 1,
            to: Math.min(currentPage * USERS_PAGE_SIZE, total),
            total,
          })}
        </span>
        <div className="flex items-center gap-2">
          {currentPage > 1 ? (
            <Link
              href={`/users${buildQuery({ q, role, status, page: currentPage - 1 })}`}
              className="btn-secondary"
            >
              {tc('previous')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('previous')}</span>
          )}
          {currentPage < pageCount ? (
            <Link
              href={`/users${buildQuery({ q, role, status, page: currentPage + 1 })}`}
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
