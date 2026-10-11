'use client';

import { useActionState, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import { formatMoney, multiplyMoney, sumLineTotals, toDecimalString } from '@/lib/money';
import {
  createSaleAction,
  searchPatientsAction,
  type PatientSearchResult,
} from '@/modules/sales/actions';
import { MAX_QTY, PAYMENT_METHODS, PAYMENT_LABEL_KEYS } from '@/modules/sales/constants';
import type { ActionResult } from '@/lib/action';

type ServiceOption = {
  id: string;
  name: string;
  nameEn: string;
  nameAm: string | null;
  currentPrice: string;
};
type DoctorOption = { id: string; fullName: string };

type Props = {
  services: ServiceOption[];
  doctors: DoctorOption[];
  locale: string;
  /** From the "Record sale" action — seeds patient / doctor / service. */
  prefill?: {
    patient: { id: string; fullName: string; phoneE164: string } | null;
    doctorId: string | null;
    serviceId: string | null;
  };
};

type Line = { serviceId: string; name: string; unitPrice: string; qty: number };

function initialLines(
  prefill: Props['prefill'],
  services: ServiceOption[],
  locale: string,
): Line[] {
  const line = prefill?.serviceId
    ? services.find((service) => service.id === prefill.serviceId)
    : null;
  return line
    ? [
        {
          serviceId: line.id,
          name:
            locale === 'am' && line.nameAm
              ? line.nameAm
              : line.nameEn || line.name,
          unitPrice: line.currentPrice,
          qty: 1,
        },
      ]
    : [];
}

export default function NewSaleForm({ services, doctors, locale, prefill }: Props) {
  const router = useRouter();
  const t = useTranslations('sales');
  const tc = useTranslations('common');
  const tv = useTranslations('validation');

  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string; receiptNumber: string }> | null,
    FormData
  >(createSaleAction, null);

  const [walkIn, setWalkIn] = useState(false);
  const [patient, setPatient] = useState<PatientSearchResult | null>(prefill?.patient ?? null);
  const [patientQuery, setPatientQuery] = useState(prefill?.patient?.fullName ?? '');
  const [results, setResults] = useState<PatientSearchResult[]>([]);
  const [serviceQuery, setServiceQuery] = useState('');
  const [lines, setLines] = useState<Line[]>(() => initialLines(prefill, services, locale));
  const [paymentMethod, setPaymentMethod] = useState<string>('CASH');
  const [doctorId, setDoctorId] = useState(prefill?.doctorId ?? '');
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (state?.ok && state.data) {
      router.push(`/sales/${state.data.id}?receipt=1`);
      router.refresh();
    }
  }, [state, router]);

  const suggestions = useMemo(() => {
    const q = serviceQuery.trim().toLowerCase();
    if (!q) return [];
    return services
      .filter(
        (service) =>
          service.nameEn.toLowerCase().includes(q) ||
          service.name.toLowerCase().includes(q) ||
          (service.nameAm ?? '').toLowerCase().includes(q),
      )
      .slice(0, 6);
  }, [serviceQuery, services]);

  const total = useMemo(
    () =>
      toDecimalString(
        sumLineTotals(lines.map((line) => ({ unitPrice: line.unitPrice, quantity: line.qty }))),
      ),
    [lines],
  );

  function onPatientQueryChange(value: string) {
    setPatientQuery(value);
    setPatient(null);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (value.trim().length < 2) {
      setResults([]);
      return;
    }
    searchTimer.current = setTimeout(() => {
      void searchPatientsAction(value).then((result) => {
        if (result.ok && result.data) setResults(result.data);
      });
    }, 250);
  }

  function addService(service: ServiceOption) {
    setLines((current) => {
      const existing = current.find((line) => line.serviceId === service.id);
      if (existing) {
        return current.map((line) =>
          line.serviceId === service.id ? { ...line, qty: Math.min(MAX_QTY, line.qty + 1) } : line,
        );
      }
      return [
        ...current,
        {
          serviceId: service.id,
          name:
            locale === 'am' && service.nameAm
              ? service.nameAm
              : service.nameEn || service.name,
          unitPrice: service.currentPrice,
          qty: 1,
        },
      ];
    });
    setServiceQuery('');
  }

  const failed = !!state && !state.ok;
  const fieldError = (key: string): string | undefined =>
    failed && state.fields?.[key] ? tv(state.fields[key] as never) : undefined;

  return (
    <form action={formAction} className="grid gap-5 lg:grid-cols-3">
      <input type="hidden" name="patientId" value={patient?.id ?? ''} />
      <input type="hidden" name="walkIn" value={walkIn ? 'true' : 'false'} />
      <input type="hidden" name="doctorId" value={doctorId} />
      <input
        type="hidden"
        name="lines"
        value={JSON.stringify(lines.map(({ serviceId, qty }) => ({ serviceId, qty })))}
      />

      <div className="space-y-5 lg:col-span-2">
        <section className="card space-y-3 p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-800">{t('patient')}</h2>
            <button
              type="button"
              className={walkIn ? 'btn-primary' : 'btn-secondary'}
              onClick={() => {
                setWalkIn((value) => !value);
                setPatient(null);
                setPatientQuery('');
                setResults([]);
              }}
            >
              {t('walkIn')}
            </button>
          </div>

          {walkIn ? (
            <p className="text-sm text-slate-600">{t('walkInHint')}</p>
          ) : patient ? (
            <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2">
              <div>
                <div className="text-sm font-medium text-slate-900">{patient.fullName}</div>
                <div className="text-xs text-slate-500">{patient.phoneE164}</div>
              </div>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  setPatient(null);
                  setPatientQuery('');
                }}
              >
                {tc('clear')}
              </button>
            </div>
          ) : (
            <div className="relative">
              <input
                className="input-field"
                type="search"
                value={patientQuery}
                onChange={(event) => onPatientQueryChange(event.target.value)}
                placeholder={t('patientSearchPlaceholder')}
                aria-invalid={!!fieldError('patientId')}
                autoComplete="off"
              />
              {results.length > 0 ? (
                <ul className="absolute z-10 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                  {results.map((result) => (
                    <li key={result.id}>
                      <button
                        type="button"
                        className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                        onClick={() => {
                          setPatient(result);
                          setPatientQuery(result.fullName);
                          setResults([]);
                        }}
                      >
                        <span className="font-medium text-slate-900">{result.fullName}</span>
                        <span className="ml-2 text-xs text-slate-500">{result.phoneE164}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
              {fieldError('patientId') ? (
                <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                  {fieldError('patientId')}
                </p>
              ) : null}
            </div>
          )}
        </section>

        <section className="card space-y-3 p-5">
          <h2 className="text-sm font-semibold text-slate-800">{t('services')}</h2>
          <div className="relative">
            <input
              className="input-field"
              type="text"
              value={serviceQuery}
              onChange={(event) => setServiceQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  if (suggestions[0]) addService(suggestions[0]);
                }
              }}
              placeholder={t('serviceSearchPlaceholder')}
              autoComplete="off"
            />
            {suggestions.length > 0 ? (
              <ul className="absolute z-10 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
                {suggestions.map((service) => (
                  <li key={service.id}>
                    <button
                      type="button"
                      className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50"
                      onClick={() => addService(service)}
                    >
                      <span className="font-medium text-slate-900">{service.nameEn || service.name}</span>
                      <span className="text-xs text-slate-500">
                        {formatMoney(service.currentPrice)}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                <tr>
                  <th className="py-2 pr-3 font-medium">{t('service')}</th>
                  <th className="py-2 pr-3 font-medium">{t('unitPrice')}</th>
                  <th className="py-2 pr-3 font-medium">{t('qty')}</th>
                  <th className="py-2 pr-3 font-medium">{t('lineTotal')}</th>
                  <th className="py-2 font-medium" />
                </tr>
              </thead>
              <tbody>
                {lines.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-6 text-center text-slate-500">
                      {t('noLines')}
                    </td>
                  </tr>
                ) : (
                  lines.map((line) => (
                    <tr key={line.serviceId} className="border-b border-slate-100 last:border-0">
                      <td className="py-2 pr-3 font-medium text-slate-900">{line.name}</td>
                      <td className="py-2 pr-3 text-slate-600">{formatMoney(line.unitPrice)}</td>
                      <td className="py-2 pr-3">
                        <input
                          className="input-field w-20"
                          type="number"
                          min={1}
                          max={MAX_QTY}
                          value={line.qty}
                          onChange={(event) => {
                            const qty = Math.max(
                              1,
                              Math.min(MAX_QTY, Number(event.target.value) || 1),
                            );
                            setLines((current) =>
                              current.map((item) =>
                                item.serviceId === line.serviceId ? { ...item, qty } : item,
                              ),
                            );
                          }}
                        />
                      </td>
                      <td className="py-2 pr-3 font-medium text-slate-900">
                        {formatMoney(multiplyMoney(line.unitPrice, line.qty))}
                      </td>
                      <td className="py-2 text-right">
                        <button
                          type="button"
                          className="text-sm text-red-600 hover:underline"
                          onClick={() =>
                            setLines((current) =>
                              current.filter((item) => item.serviceId !== line.serviceId),
                            )
                          }
                        >
                          {tc('delete')}
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          {fieldError('lines') ? (
            <p role="alert" className="text-xs font-medium text-red-600">
              {fieldError('lines')}
            </p>
          ) : null}
        </section>
      </div>

      <aside className="space-y-5">
        <section className="card space-y-3 p-5">
          <div>
            <label className="label" htmlFor="doctorId">
              {t('doctor')}
            </label>
            <select
              className="input-field"
              id="doctorId"
              value={doctorId}
              onChange={(event) => setDoctorId(event.target.value)}
            >
              <option value="">{t('noDoctor')}</option>
              {doctors.map((doctor) => (
                <option key={doctor.id} value={doctor.id}>
                  {doctor.fullName}
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
              value={paymentMethod}
              onChange={(event) => setPaymentMethod(event.target.value)}
            >
              {PAYMENT_METHODS.map((method) => (
                <option key={method} value={method}>
                  {t(PAYMENT_LABEL_KEYS[method] as never)}
                </option>
              ))}
            </select>
            {fieldError('paymentMethod') ? (
              <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                {fieldError('paymentMethod')}
              </p>
            ) : null}
          </div>
        </section>

        <section className="card space-y-3 p-5">
          <div className="flex items-center justify-between text-sm">
            <span className="text-slate-600">{t('total')}</span>
            <span className="text-lg font-semibold text-slate-900">{formatMoney(total)}</span>
          </div>
          <ActionError error={failed ? state.error : undefined} />
          <button
            type="submit"
            className="btn-primary w-full"
            disabled={pending || lines.length === 0}
          >
            {pending ? tc('saving') : t('confirmSale')}
          </button>
          <p className="text-xs text-slate-500">{t('totalsHint')}</p>
        </section>
      </aside>
    </form>
  );
}
