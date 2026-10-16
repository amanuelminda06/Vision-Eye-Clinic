import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatInTz } from '@/lib/datetime';
import { auditFilterOptions, listAudit, AUDIT_PAGE_SIZE } from '@/modules/audit';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import ExportLinks from '@/components/ExportLinks';

type SearchParams = Promise<{
  userId?: string;
  entity?: string;
  action?: string;
  from?: string;
  to?: string;
  page?: string;
}>;

function buildQuery(filters: {
  userId?: string;
  entity?: string;
  action?: string;
  from?: string;
  to?: string;
  page?: number;
}): string {
  const params = new URLSearchParams();
  if (filters.userId) params.set('userId', filters.userId);
  if (filters.entity) params.set('entity', filters.entity);
  if (filters.action) params.set('action', filters.action);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.page && filters.page > 1) params.set('page', String(filters.page));
  const query = params.toString();
  return query ? `?${query}` : '';
}

function JsonPanel({ label, value }: { label: string; value: unknown }) {
  if (value === null || value === undefined) return null;
  return (
    <div>
      <div className="text-xs font-semibold uppercase text-slate-500">{label}</div>
      <pre className="mt-1 max-h-64 overflow-auto rounded bg-slate-50 p-2 text-xs text-slate-700">
        {JSON.stringify(value, null, 2)}
      </pre>
    </div>
  );
}

export default async function AuditPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(['OWNER']);
  const sp = await searchParams;
  const t = await getTranslations('audit');
  const tc = await getTranslations('common');

  const filters = {
    userId: sp.userId?.slice(0, 64) || undefined,
    entity: sp.entity?.slice(0, 64) || undefined,
    action: sp.action?.slice(0, 64) || undefined,
    from: sp.from?.slice(0, 10) || undefined,
    to: sp.to?.slice(0, 10) || undefined,
    page: Number(sp.page) || 1,
  };
  const hasFilters = !!(
    filters.userId ||
    filters.entity ||
    filters.action ||
    filters.from ||
    filters.to
  );

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const [options, result] = await Promise.all([auditFilterOptions(), listAudit(filters, tz)]);
  const { rows, total, page: currentPage, pageCount } = result;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <ExportLinks base="/api/audit/export" query={buildQuery(filters)} />
      </div>

      <form method="get" className="card grid grid-cols-1 sm:grid-cols-3 gap-2 p-3">
        <div>
          <label className="label text-xs" htmlFor="userId">
            {t('user')}
          </label>
          <select
            className="input-field text-sm"
            id="userId"
            name="userId"
            defaultValue={filters.userId ?? ''}
          >
            <option value="">{t('allUsers')}</option>
            {options.users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="label text-xs" htmlFor="entity">
            {t('entity')}
          </label>
          <select
            className="input-field text-sm"
            id="entity"
            name="entity"
            defaultValue={filters.entity ?? ''}
          >
            <option value="">{t('allEntities')}</option>
            {options.entities.map((entity) => (
              <option key={entity} value={entity}>
                {entity}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="label text-xs" htmlFor="action">
            {t('action')}
          </label>
          <select
            className="input-field text-sm"
            id="action"
            name="action"
            defaultValue={filters.action ?? ''}
          >
            <option value="">{t('allActions')}</option>
            {options.actions.map((action) => (
              <option key={action} value={action}>
                {action}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="label text-xs" htmlFor="from">
            {t('from')}
          </label>
          <input
            className="input-field text-sm"
            id="from"
            name="from"
            type="date"
            defaultValue={filters.from ?? ''}
          />
        </div>

        <div>
          <label className="label text-xs" htmlFor="to">
            {t('to')}
          </label>
          <input
            className="input-field text-sm"
            id="to"
            name="to"
            type="date"
            defaultValue={filters.to ?? ''}
          />
        </div>
        <div className="flex items-end">
          <button type="submit" className="btn-secondary text-sm px-3 py-1.5">
            {t('apply')}
          </button>
        </div>
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('time')}</th>
              <th className="px-4 py-3 font-medium">{t('user')}</th>
              <th className="px-4 py-3 font-medium">{t('entity')}</th>
              <th className="px-4 py-3 font-medium">{t('action')}</th>
              <th className="px-4 py-3 font-medium">{t('ip')}</th>
              <th className="px-4 py-3 font-medium">{t('changes')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                  {hasFilters ? tc('noResults') : tc('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 last:border-0 align-top">
                  <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                    {formatInTz(row.createdAt, tz)}
                  </td>
                  <td className="px-4 py-3">
                    {row.userName ? (
                      <div>
                        <div className="font-medium text-slate-800">{row.userName}</div>
                        <div className="text-xs text-slate-500">{row.userEmail}</div>
                      </div>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.entity}</td>
                  <td className="px-4 py-3">
                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">
                      {row.action}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.ip ?? '—'}</td>
                  <td className="px-4 py-3">
                    <details className="min-w-56">
                      <summary className="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-800">
                        {t('changes')}
                      </summary>
                      <div className="mt-2 space-y-3">
                        {row.oldJson === null && row.newJson === null ? (
                          <p className="text-xs text-slate-500">{t('noChanges')}</p>
                        ) : (
                          <>
                            <JsonPanel label={t('old')} value={row.oldJson} />
                            <JsonPanel label={t('new')} value={row.newJson} />
                          </>
                        )}
                      </div>
                    </details>
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
            from: total === 0 ? 0 : (currentPage - 1) * AUDIT_PAGE_SIZE + 1,
            to: Math.min(currentPage * AUDIT_PAGE_SIZE, total),
            total,
          })}
        </span>
        <div className="flex items-center gap-2">
          {currentPage > 1 ? (
            <Link
              href={`/audit${buildQuery({ ...filters, page: currentPage - 1 })}`}
              className="btn-secondary"
            >
              {tc('previous')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('previous')}</span>
          )}
          {currentPage < pageCount ? (
            <Link
              href={`/audit${buildQuery({ ...filters, page: currentPage + 1 })}`}
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
