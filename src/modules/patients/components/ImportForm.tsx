'use client';

import Link from 'next/link';
import { useActionState, useState } from 'react';
import { useTranslations } from 'next-intl';
import ActionError from '@/components/ActionError';
import {
  importPatientsAction,
  type ImportPreview,
  type ImportDone,
} from '@/modules/patients/actions';
import { IMPORT_ERROR_MESSAGES } from '@/modules/patients/import';
import { toCsv } from '@/lib/csv';
import type { ActionResult } from '@/lib/action';

/** Error keys rendered from the `patients.errors.*` namespace. */
const LOCAL_ERROR_KEYS = new Set([
  'noFile',
  'fileTooLarge',
  'tooManyRows',
  'missingColumns',
  'emptyFile',
  'confirmationRequired',
]);

type Done = ImportDone;

export default function ImportForm() {
  const t = useTranslations('patients');
  const ti = useTranslations('patients.import');
  const tc = useTranslations('common');
  const [fileName, setFileName] = useState('');
  const [state, formAction, pending] = useActionState<
    ActionResult<ImportPreview | Done> | null,
    FormData
  >(importPatientsAction, null);

  const failed = !!state && !state.ok;
  const data = !failed && state?.ok ? state.data : undefined;
  const localError =
    failed && state.error && LOCAL_ERROR_KEYS.has(state.error) ? state.error : undefined;
  const preview = data && data.step === 'preview' ? data : undefined;
  const done = data && data.step === 'done' ? data : undefined;

  function downloadErrors() {
    if (!preview) return;
    const rows: Array<Array<string | number>> = [];
    for (const row of preview.rows) {
      if (row.errors.length === 0) continue;
      for (const issue of row.errors) {
        rows.push([
          row.rowNumber,
          issue.field,
          IMPORT_ERROR_MESSAGES[issue.key] ?? issue.key,
          row.raw[issue.field] ?? '',
        ]);
      }
    }
    const blob = new Blob([toCsv(['row', 'field', 'issue', 'value'], rows)], {
      type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'patient-import-errors.csv';
    anchor.click();
    URL.revokeObjectURL(url);
  }

  if (done) {
    return (
      <div className="card space-y-4 p-6">
        <p className="text-sm font-medium text-emerald-700">
          {ti('done', { imported: done.imported, skipped: done.skipped })}
        </p>
        <div className="flex items-center gap-3">
          <Link href="/patients" className="btn-primary">
            {t('title')}
          </Link>
          <button
            type="button"
            className="btn-secondary"
            onClick={() => {
              setFileName('');
              window.location.reload();
            }}
          >
            {ti('importAnother')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <form action={formAction} className="card max-w-4xl space-y-4 p-6">
      <div>
        <label className="label" htmlFor="file">
          {ti('chooseFile')}
        </label>
        <input
          className="input-field"
          id="file"
          name="file"
          type="file"
          accept=".csv,text/csv"
          required={!preview}
          onChange={(event) => setFileName(event.target.files?.[0]?.name ?? '')}
          disabled={pending}
        />
        <p className="mt-1 text-xs text-slate-500">{fileName || ti('csvHint')}</p>
        <p className="mt-1 text-xs text-slate-500">{ti('columnsHint')}</p>
        <p className="mt-1 text-xs text-slate-400">{ti('exampleHint')}</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          name="step"
          value="preview"
          className="btn-primary"
          disabled={pending}
        >
          {pending ? tc('loading') : ti('validate')}
        </button>
        <Link href="/api/patients/template" className="btn-secondary">
          {ti('downloadTemplate')}
        </Link>
      </div>

      {localError ? (
        <p role="alert" className="text-sm font-medium text-red-600">
          {ti(`errors.${localError}` as never)}
        </p>
      ) : null}
      {!localError ? <ActionError error={failed ? state.error : undefined} /> : null}

      {preview ? (
        <>
          <input type="hidden" name="csv" value={preview.csv} />
          <input type="hidden" name="confirm" value="true" />
          <div className="rounded-lg border border-slate-200 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
              <p className="font-medium text-slate-800">{ti('previewTitle')}</p>
              <p className="text-slate-600">
                {ti('summary', { valid: preview.validCount, errors: preview.errorCount })}
              </p>
            </div>

            <div className="mt-3 max-h-80 overflow-auto">
              <table className="w-full text-left text-xs">
                <thead className="sticky top-0 border-b border-slate-200 bg-white text-slate-500 uppercase">
                  <tr>
                    <th className="px-2 py-2 font-medium">{ti('rowCol')}</th>
                    <th className="px-2 py-2 font-medium">{t('fullName')}</th>
                    <th className="px-2 py-2 font-medium">{t('phone')}</th>
                    <th className="px-2 py-2 font-medium">{tc('status')}</th>
                    <th className="px-2 py-2 font-medium">{ti('issuesCol')}</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.rows.map((row) => (
                    <tr key={row.rowNumber} className="border-b border-slate-100 last:border-0">
                      <td className="px-2 py-1.5 text-slate-500">{row.rowNumber}</td>
                      <td className="px-2 py-1.5 text-slate-700">{row.raw.fullName}</td>
                      <td className="px-2 py-1.5 text-slate-700">{row.raw.phone}</td>
                      <td className="px-2 py-1.5">
                        {row.errors.length === 0 ? (
                          <span className="text-emerald-700">{tc('active')}</span>
                        ) : (
                          <span className="text-red-600">{t('hasErrors')}</span>
                        )}
                      </td>
                      <td className="px-2 py-1.5 text-red-600">
                        {row.errors
                          .map((issue) => IMPORT_ERROR_MESSAGES[issue.key] ?? issue.key)
                          .join('; ')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <button
              type="submit"
              name="step"
              value="import"
              className="btn-primary"
              disabled={pending || preview.validCount === 0}
            >
              {pending ? tc('saving') : ti('confirmImport', { count: preview.validCount })}
            </button>
            {preview.errorCount > 0 ? (
              <button type="button" className="btn-secondary" onClick={downloadErrors}>
                {ti('errorReport')}
              </button>
            ) : null}
          </div>
          <p className="text-xs text-slate-500">{ti('confirmHint')}</p>
        </>
      ) : null}
    </form>
  );
}
