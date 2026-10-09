import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { formatInTz } from '@/lib/datetime';
import { getSettingsMap, timezoneFrom, settingsFromMap } from '@/modules/settings';
import {
  PrintButtons,
  ReceiptView,
  ReasonActionButton,
  SALES_ROLES,
  STATUS_LABEL_KEYS,
  canApproveVoid,
  canSeeAllSales,
  canVoidDirectly,
  getSale,
  listSaleAudit,
} from '@/modules/sales';
import {
  approveVoidRequestAction,
  rejectVoidRequestAction,
  requestVoidAction,
  voidSaleAction,
} from '@/modules/sales/actions';

const AUDIT_LABEL_KEYS: Record<string, string> = {
  SALE_CREATED: 'auditCreated',
  SALE_VOID_REQUESTED: 'auditVoidRequested',
  SALE_VOID_APPROVED: 'auditVoidApproved',
  SALE_VOID_REQUEST_REJECTED: 'auditVoidRejected',
  SALE_VOIDED: 'auditVoided',
};

type Params = Promise<{ id: string }>;

export default async function SaleDetailPage({ params }: { params: Params }) {
  const actor = await requirePageRole(SALES_ROLES);
  const { id } = await params;
  const sale = await getSale(id);
  if (!sale) notFound();
  if (!canSeeAllSales(actor.role) && sale.createdBy !== actor.id) notFound();

  const t = await getTranslations('sales');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);
  const clinic = settingsFromMap(settings);
  const audit = await listSaleAudit(sale.id);

  const pending = sale.status === 'CONFIRMED' && !!sale.voidRequestedBy;
  const canVoid = sale.status === 'CONFIRMED' && canVoidDirectly(actor.role) && !pending;
  const canRequest = sale.status === 'CONFIRMED' && !pending && !canVoidDirectly(actor.role);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold text-slate-900">{sale.receiptNumber}</h1>
          <span
            className={
              'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ' +
              (sale.status === 'CONFIRMED'
                ? 'bg-emerald-50 text-emerald-700'
                : sale.status === 'VOIDED'
                  ? 'bg-red-50 text-red-700'
                  : 'bg-amber-50 text-amber-700')
            }
          >
            {t(STATUS_LABEL_KEYS[sale.status as never] as never)}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <Link href="/sales" className="btn-secondary">
            {tc('back')}
          </Link>
          <PrintButtons targetId="receipt" />
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <ReceiptView
            sale={sale}
            clinicName={clinic.clinic_name}
            currency={clinic.currency}
            timeZone={tz}
          />

          <div className="card space-y-3 p-5">
            <h2 className="text-sm font-semibold text-slate-800">{t('auditTrail')}</h2>
            {audit.length === 0 ? (
              <p className="text-sm text-slate-500">{tc('empty')}</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {audit.map((entry) => (
                  <li
                    key={entry.id}
                    className="flex flex-wrap items-baseline justify-between gap-2"
                  >
                    <span className="text-slate-700">
                      {AUDIT_LABEL_KEYS[entry.action]
                        ? t(AUDIT_LABEL_KEYS[entry.action] as never)
                        : entry.action}
                    </span>
                    <span className="text-xs text-slate-500">
                      {entry.userName ?? '—'} · {formatInTz(entry.createdAt, tz)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <aside className="space-y-5">
          <div className="card space-y-3 p-5">
            <h2 className="text-sm font-semibold text-slate-800">{tc('actions')}</h2>

            {canVoid ? (
              <ReasonActionButton
                action={voidSaleAction}
                saleId={sale.id}
                label={t('voidSale')}
                title={t('voidSaleTitle')}
                body={t('voidSaleBody')}
                variant="danger"
                redirectTo="/sales"
              />
            ) : null}

            {canRequest ? (
              <ReasonActionButton
                action={requestVoidAction}
                saleId={sale.id}
                label={t('requestVoid')}
                title={t('requestVoidTitle')}
                body={t('requestVoidBody')}
                variant="secondary"
              />
            ) : null}

            {pending && canApproveVoid(actor.role) ? (
              <div className="space-y-2 border-t border-slate-100 pt-3">
                <p className="text-xs text-slate-500">{t('approveHint')}</p>
                <div className="flex flex-wrap gap-2">
                  <ReasonActionButton
                    action={approveVoidRequestAction}
                    saleId={sale.id}
                    label={t('approveVoid')}
                    title={t('approveVoidTitle')}
                    variant="primary"
                    requireReason={false}
                    redirectTo="/sales/voids"
                  />
                  <ReasonActionButton
                    action={rejectVoidRequestAction}
                    saleId={sale.id}
                    label={t('rejectVoid')}
                    title={t('rejectVoidTitle')}
                    variant="danger"
                  />
                </div>
              </div>
            ) : null}

            {sale.status !== 'CONFIRMED' && !pending ? (
              <p className="text-sm text-slate-500">{t('noActions')}</p>
            ) : null}
          </div>

          {pending ? (
            <div className="card space-y-2 border border-amber-200 bg-amber-50 p-5">
              <h2 className="text-sm font-semibold text-amber-800">{t('voidRequestPending')}</h2>
              <p className="text-sm text-amber-800">{sale.voidRequestReason}</p>
              <p className="text-xs text-amber-700">
                {t('voidRequestedBy', { name: sale.voidRequestedByName ?? '—' })}
              </p>
            </div>
          ) : null}

          {sale.status === 'VOIDED' ? (
            <div className="card space-y-2 border border-red-200 bg-red-50 p-5">
              <h2 className="text-sm font-semibold text-red-800">{t('voidedWatermark')}</h2>
              <p className="text-sm text-red-800">{sale.voidReason}</p>
              <p className="text-xs text-red-700">
                {t('voidedMeta', {
                  name: sale.voidedByName ?? '—',
                  date: sale.voidedAt ? formatInTz(sale.voidedAt, tz) : '—',
                })}
              </p>
            </div>
          ) : null}

          <div className="card space-y-2 p-5 text-sm text-slate-600">
            <div className="flex justify-between">
              <span>{t('createdBy')}</span>
              <span className="text-slate-800">{sale.createdByName ?? '—'}</span>
            </div>
            <div className="flex justify-between">
              <span>{t('createdAt')}</span>
              <span className="text-slate-800">{formatInTz(sale.createdAt, tz)}</span>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
