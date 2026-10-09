'use client';

import { useMemo, useState } from 'react';
import { useTranslations } from 'next-intl';
import type { TemplateRow } from '../queries';
import { TEMPLATE_LABEL_KEYS, TEMPLATE_VARIABLES, TEMPLATE_SAFETY_NOTE_KEY } from '../constants';
import { countSms, previewTemplate, unknownVariables } from '../templates';
import { saveMessageTemplateAction } from '../actions';

type Props = { templates: TemplateRow[] };

export default function TemplateEditor({ templates }: Props) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const [selectedId, setSelectedId] = useState(templates[0]?.id ?? '');
  const selected = templates.find((row) => row.id === selectedId) ?? null;
  const [body, setBody] = useState(selected?.body ?? '');
  const [active, setActive] = useState(selected?.active ?? true);
  const [toast, setToast] = useState('');

  const count = useMemo(() => countSms(body), [body]);
  const unknown = useMemo(() => unknownVariables(body), [body]);
  const preview = useMemo(() => previewTemplate(body).text, [body]);

  function selectTemplate(id: string) {
    const row = templates.find((item) => item.id === id) ?? null;
    setSelectedId(id);
    setBody(row?.body ?? '');
    setActive(row?.active ?? true);
  }

  async function save() {
    if (!selected) return;
    const fd = new FormData();
    fd.set('id', selected.id);
    fd.set('key', selected.key);
    fd.set('language', selected.language);
    fd.set('body', body);
    fd.set('active', active ? 'true' : 'false');
    const result = await saveMessageTemplateAction(null, fd);
    if (!result.ok) {
      setToast(result.error ?? 'unknownError');
      return;
    }
    setToast(t('templateSaved'));
    window.setTimeout(() => setToast(''), 1500);
  }

  if (!selected) return null;

  return (
    <div className="space-y-4">
      <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
        {t(TEMPLATE_SAFETY_NOTE_KEY as never)}
      </p>

      <div className="grid gap-4 md:grid-cols-[16rem_1fr]">
        <div className="card max-h-96 overflow-y-auto p-2">
          {templates.map((row) => (
            <button
              key={row.id}
              type="button"
              onClick={() => selectTemplate(row.id)}
              className={
                'block w-full rounded-md px-3 py-2 text-left text-sm ' +
                (row.id === selectedId
                  ? 'bg-slate-100 text-slate-900'
                  : 'text-slate-600 hover:bg-slate-50')
              }
            >
              <span className="block font-medium">{t(TEMPLATE_LABEL_KEYS[row.key] as never)}</span>
              <span className="text-[11px] uppercase text-slate-400">
                {row.language === 'am' ? 'am · ' + t('amDraft') : 'en'}
              </span>
            </button>
          ))}
        </div>

        <div className="space-y-3">
          <textarea
            className="input-field min-h-40"
            value={body}
            onChange={(event) => setBody(event.target.value)}
          />

          <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
            <span>
              {count.length} {t('characters')} · {count.parts} {t('parts')} · {count.encoding}
            </span>
            {count.parts > 1 ? (
              <span className="text-amber-700">{t('multiPartWarning')}</span>
            ) : null}
            <label className="ml-auto flex items-center gap-2">
              <input
                type="checkbox"
                checked={active}
                onChange={(event) => setActive(event.target.checked)}
              />
              {t('active')}
            </label>
          </div>

          {unknown.length ? (
            <p className="text-xs text-red-600">
              {t('unknownVariables')}: {unknown.join(', ')}
            </p>
          ) : null}

          <div className="rounded-md border border-slate-200 p-3">
            <p className="text-xs font-medium text-slate-500">{t('preview')}</p>
            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800">{preview}</p>
          </div>

          <div className="flex flex-wrap gap-2">
            {TEMPLATE_VARIABLES.map((variable) => (
              <button
                key={variable}
                type="button"
                className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-200"
                onClick={() => setBody((prev) => `${prev}{${variable}}`)}
              >
                {`{${variable}}`}
              </button>
            ))}
          </div>

          <div className="flex justify-end">
            <button type="button" className="btn-primary" onClick={() => void save()}>
              {tc('save')}
            </button>
          </div>
        </div>
      </div>

      {toast ? (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs text-white shadow-lg">
          {toast}
        </div>
      ) : null}
    </div>
  );
}
