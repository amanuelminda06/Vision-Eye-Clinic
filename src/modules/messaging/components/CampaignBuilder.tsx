'use client';

import { useActionState, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { TEMPLATE_LABEL_KEYS } from '../constants';
import { SERVICE_CATEGORIES, CATEGORY_LABEL_KEYS } from '@/modules/services/categories';
import type { TemplateRow } from '../queries';
import { previewCampaignAction, createCampaignAction } from '../actions';

type Preview = { matched: number; eligible: number; overCap: boolean };

export default function CampaignBuilder({
  templates,
  canSend,
}: {
  templates: TemplateRow[];
  canSend: boolean;
}) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const ts = useTranslations('services');
  const router = useRouter();

  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string; created: number }> | null,
    FormData
  >(createCampaignAction, null);

  const [templateKey, setTemplateKey] = useState('APPT_REMINDER');
  const [languageMode, setLanguageMode] = useState('PATIENT');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [ack, setAck] = useState(false);
  const [toast, setToast] = useState('');

  const seen = useMemo(() => {
    const map = new Map<string, TemplateRow>();
    for (const row of templates) if (!map.has(row.key)) map.set(row.key, row);
    return [...map.values()];
  }, [templates]);

  useEffect(() => {
    if (state?.ok && state.data) router.push(`/messages/campaigns/${state.data.id}`);
  }, [state, router]);

  async function runPreview(form: HTMLFormElement) {
    const result = await previewCampaignAction(null, new FormData(form));
    const data = result.ok ? result.data : undefined;
    if (data) setPreview({ matched: data.matched, eligible: data.eligible, overCap: data.overCap });
    else if (!result.ok) setToast(result.error ?? 'unknownError');
  }

  if (!canSend) return <p className="card p-5 text-sm text-slate-600">{t('campaignsForbidden')}</p>;

  return (
    <form className="space-y-4" action={formAction}>
      <div className="card space-y-3 p-4">
        <div>
          <label className="text-xs uppercase text-slate-500">{t('campaignTitleLabel')}</label>
          <input className="input-field" name="title" required maxLength={120} />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="text-xs uppercase text-slate-500">{t('template')}</label>
            <select
              className="input-field"
              name="templateKey"
              value={templateKey}
              onChange={(event) => setTemplateKey(event.target.value)}
            >
              {seen.map((row) => (
                <option key={row.key} value={row.key}>
                  {t(TEMPLATE_LABEL_KEYS[row.key] as never)}
                </option>
              ))}
              <option value="CUSTOM">{t('tplCustom')}</option>
            </select>
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('languageMode')}</label>
            <select
              className="input-field"
              name="languageMode"
              value={languageMode}
              onChange={(event) => setLanguageMode(event.target.value)}
            >
              <option value="PATIENT">{t('languagePatient')}</option>
              <option value="FIXED">{t('languageFixed')}</option>
            </select>
          </div>
        </div>
        {languageMode === 'FIXED' ? (
          <select className="input-field" name="fixedLanguage" defaultValue="en">
            <option value="en">English</option>
            <option value="am">አማርኛ</option>
          </select>
        ) : (
          <input type="hidden" name="fixedLanguage" value="en" />
        )}
        {templateKey === 'CUSTOM' ? (
          <textarea
            className="input-field min-h-24"
            name="customBody"
            placeholder={t('customMessage')}
          />
        ) : null}
      </div>

      <fieldset className="card space-y-3 p-4">
        <legend className="px-1 text-sm font-semibold text-slate-700">{t('filterTitle')}</legend>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterTags')}</label>
            <input className="input-field" name="tags" placeholder={t('tagsHint')} />
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterTagMode')}</label>
            <select className="input-field" name="tagMode" defaultValue="ANY">
              <option value="ANY">{t('tagAny')}</option>
              <option value="ALL">{t('tagAll')}</option>
            </select>
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterLanguage')}</label>
            <select className="input-field" name="language" defaultValue="">
              <option value="">{t('any')}</option>
              <option value="en">English</option>
              <option value="am">አማርኛ</option>
            </select>
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterUpcoming')}</label>
            <select className="input-field" name="upcoming" defaultValue="ANY">
              <option value="ANY">{t('any')}</option>
              <option value="HAS">{t('hasUpcoming')}</option>
              <option value="NONE">{t('noUpcoming')}</option>
            </select>
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterLastVisitFrom')}</label>
            <input className="input-field" type="date" name="lastVisitFrom" />
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterLastVisitTo')}</label>
            <input className="input-field" type="date" name="lastVisitTo" />
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterCategory')}</label>
            <select className="input-field" name="serviceCategory" defaultValue="">
              <option value="">{t('any')}</option>
              {SERVICE_CATEGORIES.map((category) => (
                <option key={category} value={category}>
                  {ts(CATEGORY_LABEL_KEYS[category] as never)}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterBirthdayMonth')}</label>
            <input
              className="input-field"
              type="number"
              name="birthdayMonth"
              min={0}
              max={12}
              defaultValue={0}
            />
          </div>
          <div>
            <label className="text-xs uppercase text-slate-500">{t('expiresAt')}</label>
            <input className="input-field" type="date" name="expiresAt" />
          </div>
        </div>
        <p className="text-xs text-slate-500">{t('consentAlways')}</p>
      </fieldset>

      {preview ? (
        <div className="card space-y-1 p-4 text-sm">
          <p>{t('previewMatched', { count: preview.matched })}</p>
          <p>{t('previewEligible', { count: preview.eligible })}</p>
          {preview.overCap ? <p className="font-medium text-red-600">{t('overCap')}</p> : null}
        </div>
      ) : null}

      <div className="card space-y-2 p-4">
        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            name="acknowledgedGroupWarning"
            value="true"
            checked={ack}
            onChange={(event) => setAck(event.target.checked)}
          />
          {t('groupWarning')}
        </label>
      </div>

      {state && !state.ok ? <p className="text-sm text-red-600">{state.error}</p> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <button
          type="button"
          className="btn-secondary"
          onClick={(event) => {
            const form = event.currentTarget.closest('form');
            if (form) void runPreview(form);
          }}
        >
          {t('preview')}
        </button>
        <button type="submit" className="btn-primary" disabled={pending || !ack}>
          {pending ? tc('saving') : t('createCampaign')}
        </button>
      </div>

      {toast ? (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs text-white shadow-lg">
          {toast}
        </div>
      ) : null}
    </form>
  );
}
