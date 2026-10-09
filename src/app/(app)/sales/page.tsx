import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import ExportLinks from '@/components/ExportLinks';
import { formatMoney } from '@/lib/money';
import { formatInTz, clinicDateKey } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { listServiceOptions } from '@/modules/services';
import {
  PAYMENT_METHODS,
  PAYMENT_LABEL_KEYS,
  SALE_STATUSES,
  STATUS_LABEL_KEYS,
  SALES_PAGE_SIZE,
  SALES_ROLES,
  canSeeAllSales,
  isPaymentMethod,
  isSaleStatus,
  listDoctorOptions,
  listSales,
  scopeToActor,
  type SaleListFilters,
} from '@/modules/sales';

type SearchParams = Promise<{
  from?: string;
  to?: string;
  patient?: string;
  serviceId?: string;
  doctorId?: string;
  status?: string;
  paymentMethod?: string;
  page?: string;
}>;

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function buildQuery(filters: SaleListFilters, patch: Partial<SaleListFilters> = {}): string {
  const merged = { ...filters, ...patch };
  const params = new URLSearchParams();
  if (merged.from) params.set('from', merged.from);
  if (merged.to) params.set('to', merged.to);
  if (merged.patient) params.set('patient', merged.patient);
  if (merged.serviceId) params.set('serviceId', merged.serviceId);
  if (merged.doctorId) params.set('doctorId', merged.doctorId);
  if (merged.status) params.set('status', merged.status);
  if (merged.paymentMethod) params.set('paymentMethod', merged.paymentMethod);
  if (merged.page && merged.page > 1) params.set('page', String(merged.page));
  const query = params.toString();
  return query ? `?${query}` : '';
}

export default async function SalesPage({ searchParams }: { searchParams: SearchParams }) {
  const actor = await requirePageRole(SALES_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('sales');
  const tc = await getTranslations('common');

  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const allSales = canSeeAllSales(actor.role);

  const filters: SaleListFilters = {
    from: sp.from && DATE_RE.test(sp.from) ? sp.from : '',
    to: sp.to && DATE_RE.test(sp.to) ? sp.to : '',
    patient: sp.patient?.slice(0, 100) ?? '',
    serviceId: sp.serviceId?.slice(0, 64) ?? '',
    doctorId: sp.doctorId?.slice(0, 64) ?? '',
    status: isSaleStatus(sp.status) ? sp.status : '',
    paymentMethod: isPaymentMethod(sp.paymentMethod) ? sp.paymentMethod : '',
    page: Number(sp.page) || 1,
  };
  const scoped = scopeToActor(filters, actor, clinicDateKey(new Date(), tz));

  const [services, doctors, result] = await Promise.all([
    listServiceOptions(),
    listDoctorOptions(),
    listSales(scoped, tz),
  ]);
  const { rows, total, page: currentPage, pageCount, sumTotal } = result;

  const hasFilters = !!(
    filters.from ||
    filters.to ||
    filters.patient ||
    filters.serviceId ||
    filters.doctorId ||
    filters.status ||
    filters.paymentMethod
  );

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <div className="flex items-center gap-2">
          {allSales ? (
            <Link href="/sales/voids" className="btn-secondary">
              {t('voidRequests')}
            </Link>
          ) : null}
          <Link href="/sales/new" className="btn-primary">
            {t('newSale')}
          </Link>
        </div>
      </div>

      {allSales ? (
        <form method="get" className="card flex flex-wrap items-end gap-3 p-4">
          <div>
            <label className="label" htmlFor="from">
              {t('from')}
            </label>
            <input
              className="input-field"
              id="from"
              name="from"
              type="date"
              defaultValue={filters.from}
            />
          </div>
          <div>
            <label className="label" htmlFor="to">
              {t('to')}
            </label>
            <input
              className="input-field"
              id="to"
              name="to"
              type="date"
              defaultValue={filters.to}
            />
          </div>
          <div className="min-w-44 grow">
            <label className="label" htmlFor="patient">
              {t('patient')}
            </label>
            <input
              className="input-field"
              id="patient"
              name="patient"
              type="search"
              defaultValue={filters.patient}
              placeholder={t('patientFilterPlaceholder')}
            />
          </div>
          <div>
            <label className="label" htmlFor="serviceId">
              {t('service')}
            </label>
            <select
              className="input-field"
              id="serviceId"
              name="serviceId"
              defaultValue={filters.serviceId}
            >
              <option value="">{tc('all')}</option>
              {services.map((service) => (
                <option key={service.id} value={service.id}>
                  {service.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="doctorId">
              {t('doctor')}
            </label>
            <select
              className="input-field"
              id="doctorId"
              name="doctorId"
              defaultValue={filters.doctorId}
            >
              <option value="">{tc('all')}</option>
              {doctors.map((doctor) => (
                <option key={doctor.id} value={doctor.id}>
                  {doctor.fullName}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="status">
              {tc('status')}
            </label>
            <select className="input-field" id="status" name="status" defaultValue={filters.status}>
              <option value="">{tc('all')}</option>
              {SALE_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {t(STATUS_LABEL_KEYS[status] as never)}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="label" htmlFor="paymentMethod">
              {t('paymentMethod')}
            </label>
            <select
              className="input-field"
              id="paymentMethod"
              name="paymentMethod"
              defaultValue={filters.paymentMethod}
            >
              <option value="">{tc('all')}</option>
              {PAYMENT_METHODS.map((method) => (
                <option key={method} value={method}>
                  {t(PAYMENT_LABEL_KEYS[method] as never)}
                </option>
              ))}
            </select>
          </div>
          <button type="submit" className="btn-secondary">
            {tc('filter')}
          </button>
          <Link href="/sales" className="pb-2 text-sm text-slate-500 hover:text-slate-800">
            {tc('clear')}
          </Link>
          <ExportLinks base="/api/sales/export" query={buildQuery(scoped, { page: 1 })} />
        </form>
      ) : null}

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{t('receiptNumber')}</th>
              <th className="px-4 py-3 font-medium">{t('date')}</th>
              <th className="px-4 py-3 font-medium">{t('patient')}</th>
              <th className="px-4 py-3 font-medium">{t('doctor')}</th>
              <th className="px-4 py-3 font-medium">{t('paymentMethod')}</th>
              <th className="px-4 py-3 font-medium">{tc('status')}</th>
              <th className="px-4 py-3 text-right font-medium">{t('total')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
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
                    <Link
                      href={`/sales/${row.id}`}
                      prefetch={false}
                      className="font-medium text-slate-900 hover:underline"
                    >
                      {row.receiptNumber}
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {formatInTz(row.saleDate, tz, { dateStyle: 'medium', timeStyle: 'short' })}
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {row.patient?.fullName ?? t('walkIn')}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{row.doctor?.fullName ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {t(PAYMENT_LABEL_KEYS[row.paymentMethod] as never)}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
                        (row.status === 'CONFIRMED'
                          ? 'bg-emerald-50 text-emerald-700'
                          : row.status === 'VOIDED'
                            ? 'bg-red-50 text-red-700'
                            : 'bg-amber-50 text-amber-700')
                      }
                    >
                      {t(STATUS_LABEL_KEYS[row.status] as never)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right font-medium text-slate-900">
                    {formatMoney(row.total)}
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
            from: total === 0 ? 0 : (currentPage - 1) * SALES_PAGE_SIZE + 1,
            to: Math.min(currentPage * SALES_PAGE_SIZE, total),
            total,
          })}
          {allSales ? (
            <span className="ml-2 font-medium text-slate-900">
              {t('sumTotal', { total: formatMoney(sumTotal) })}
            </span>
          ) : null}
        </span>
        <div className="flex items-center gap-2">
          {currentPage > 1 ? (
            <Link
              href={`/sales${buildQuery(scoped, { page: currentPage - 1 })}`}
              className="btn-secondary"
            >
              {tc('previous')}
            </Link>
          ) : (
            <span className="btn-secondary cursor-not-allowed opacity-50">{tc('previous')}</span>
          )}
          <span className="text-xs text-slate-500">
            {tc('page')} {currentPage} {tc('of')} {pageCount}
          </span>
          {currentPage < pageCount ? (
            <Link
              href={`/sales${buildQuery(scoped, { page: currentPage + 1 })}`}
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
