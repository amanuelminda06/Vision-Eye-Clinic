import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatMoney } from '@/lib/money';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  CATEGORY_LABEL_KEYS,
  PriceHistoryModal,
  SERVICES_ROLES,
  SERVICE_CATEGORIES,
  isServiceCategory,
  listServices,
  type ServiceListFilters,
} from '@/modules/services';

type SearchParams = Promise<{ q?: string; category?: string; status?: string }>;

export default async function ServicesPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(SERVICES_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('services');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const q = sp.q?.slice(0, 100) ?? '';
  const category = isServiceCategory(sp.category) ? sp.category : '';
  const status = sp.status === 'true' || sp.status === 'false' ? sp.status : '';
  const filters: ServiceListFilters = { q, category, status };
  const rows = await listServices(filters);
  const hasFilters = !!(q || category || status);

  const categoryLabel = (value: string): string =>
    isServiceCategory(value) ? t(CATEGORY_LABEL_KEYS[value] as never) : value;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <Link href="/dashboard/services/new" className="btn-primary">
          {t('newService')}
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
          <label className="label text-xs" htmlFor="category">
            {t('category')}
          </label>
          <select className="input-field text-sm" id="category" name="category" defaultValue={category}>
            <option value="">{tc('all')}</option>
            {SERVICE_CATEGORIES.map((value) => (
              <option key={value} value={value}>
                {t(CATEGORY_LABEL_KEYS[value] as never)}
              </option>
            ))}
          </select>
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
          <Link
            href="/dashboard/services"
            className="ml-2 pb-1 text-xs text-slate-500 hover:text-slate-800"
          >
            {tc('clear')}
          </Link>
        </div>
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('name')}</th>
              <th className="px-4 py-3 font-medium">{t('category')}</th>
              <th className="px-4 py-3 font-medium">{t('currentPrice')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                  {hasFilters ? tc('noResults') : tc('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{row.name}</div>
                    {row.nameAm ? <div className="text-xs text-slate-500">{row.nameAm}</div> : null}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{categoryLabel(row.category)}</td>
                  <td className="px-4 py-3 font-medium text-slate-900">
                    {formatMoney(row.currentPrice)}
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
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link href={`/dashboard/services/${row.id}/edit`} className="btn-secondary">
                        {tc('edit')}
                      </Link>
                      <PriceHistoryModal serviceId={row.id} serviceName={row.name} tz={tz} />
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
