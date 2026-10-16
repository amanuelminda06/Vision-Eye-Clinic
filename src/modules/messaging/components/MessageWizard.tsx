'use client';

import { useActionState, useCallback, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { ActionResult } from '@/lib/action';
import { SERVICE_CATEGORIES, CATEGORY_LABEL_KEYS } from '@/modules/services/categories';
import {
  GROUP_BLOCK_LABEL_KEYS,
  LIBRARY_CATEGORY_LABEL_KEYS,
  TEMPLATE_LABEL_KEYS,
} from '../constants';
import type { AudienceRow, DraftRow, LibraryRow, TemplateRow } from '../queries';
import {
  createCampaignAction,
  previewCampaignAction,
  saveAudienceAction,
  saveLibraryItemAction,
  saveMessageDraftAction,
  searchPatientsAction,
} from '../actions';
import { normalizeRecipientFilter } from '../recipient-filter';

type ServiceOption = { id: string; name: string };
type Pickable = { id: string; fullName: string; phoneE164: string };

type Preview = {
  matched: number;
  eligible: number;
  overCap: boolean;
  groupSafe: boolean;
  groupBlockReasons: string[];
};

type FilterState = {
  tags: string;
  tagMode: string;
  serviceIds: string[];
  serviceCategory: string;
  language: string;
  lastVisitFrom: string;
  lastVisitTo: string;
  boughtServiceFrom: string;
  boughtServiceTo: string;
  upcoming: string;
  birthdayMonth: string;
  presetRange: string;
  handPicked: Pickable[];
};

const initialFilter: FilterState = {
  tags: '',
  tagMode: 'ANY',
  serviceIds: [],
  serviceCategory: '',
  language: '',
  lastVisitFrom: '',
  lastVisitTo: '',
  boughtServiceFrom: '',
  boughtServiceTo: '',
  upcoming: 'ANY',
  birthdayMonth: '0',
  presetRange: '',
  handPicked: [],
};

function buildFilterFd(filter: FilterState): FormData {
  const fd = new FormData();
  fd.set('tags', filter.tags);
  fd.set('tagMode', filter.tagMode);
  for (const id of filter.serviceIds) fd.append('serviceIds', id);
  fd.set('serviceCategory', filter.serviceCategory);
  fd.set('language', filter.language);
  fd.set('lastVisitFrom', filter.lastVisitFrom);
  fd.set('lastVisitTo', filter.lastVisitTo);
  fd.set('boughtServiceFrom', filter.boughtServiceFrom);
  fd.set('boughtServiceTo', filter.boughtServiceTo);
  fd.set('upcoming', filter.upcoming);
  fd.set('birthdayMonth', filter.birthdayMonth);
  fd.set('presetRange', filter.presetRange);
  for (const picked of filter.handPicked) fd.append('handPickedIds', picked.id);
  return fd;
}

export default function MessageWizard({
  templates,
  services,
  library,
  drafts,
  audiences,
  canSend,
  canManageLibrary,
  canManageAudiences,
  allowGroupOverride,
  presetDelivery,
  createLabelKey,
  initialDraftId,
  initialLibraryId,
}: {
  templates: TemplateRow[];
  services: ServiceOption[];
  library: LibraryRow[];
  drafts: DraftRow[];
  audiences: AudienceRow[];
  canSend: boolean;
  canManageLibrary: boolean;
  canManageAudiences: boolean;
  allowGroupOverride: boolean;
  presetDelivery: 'INDIVIDUAL' | 'GROUP';
  createLabelKey: 'createCampaign' | 'newMessage';
  initialDraftId?: string;
  initialLibraryId?: string;
}) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const ts = useTranslations('services');
  const router = useRouter();

  const initialDraft = initialDraftId
    ? drafts.find((row) => row.id === initialDraftId)
    : undefined;
  const initialLibrary = initialLibraryId
    ? library.find((row) => row.id === initialLibraryId)
    : undefined;
  const initialCustom = initialDraft ?? initialLibrary;

  const [state, formAction, pending] = useActionState<
    ActionResult<{ id: string; created: number; batches: number }> | null,
    FormData
  >(createCampaignAction, null);

  const [step, setStep] = useState(initialCustom ? 2 : 1);
  const [filter, setFilter] = useState<FilterState>(initialFilter);
  const [title, setTitle] = useState(initialCustom?.title ?? '');
  const [body, setBody] = useState(initialCustom?.body ?? '');
  const [language, setLanguage] = useState(initialCustom?.language ?? 'en');
  const [templateKey, setTemplateKey] = useState(initialCustom ? 'CUSTOM' : 'APPT_REMINDER');
  const [languageMode, setLanguageMode] = useState('PATIENT');
  const [fixedLanguage, setFixedLanguage] = useState('en');
  const [libraryItemId, setLibraryItemId] = useState(initialLibraryId ?? '');
  const [draftId, setDraftId] = useState(initialDraftId ?? '');
  const [category, setCategory] = useState('REMINDER');
  const [deliveryMode, setDeliveryMode] = useState<'INDIVIDUAL' | 'GROUP'>(presetDelivery);
  const [ack, setAck] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [toast, setToast] = useState('');

  const [audienceName, setAudienceName] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<Pickable[]>([]);
  const [searching, setSearching] = useState(false);

  const seenTemplates = useMemo(() => {
    const map = new Map<string, TemplateRow>();
    for (const row of templates) if (!map.has(row.key)) map.set(row.key, row);
    return [...map.values()];
  }, [templates]);

  const showToast = useCallback((message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 2500);
  }, []);

  useEffect(() => {
    if (state?.ok && state.data) router.push(`/messages/campaigns/${state.data.id}`);
  }, [state, router]);

  const runPreview = useCallback(async () => {
    const result = await previewCampaignAction(null, buildFilterFd(filter));
    if (result.ok && result.data) setPreview(result.data);
    else if (!result.ok) showToast(result.error ?? 'unknownError');
  }, [filter, showToast]);

  const changeFilter = useCallback(
    (patch: Partial<FilterState>) => {
      setFilter((current) => ({ ...current, ...patch }));
      setPreview(null);
    },
    [],
  );

  const saveDraft = useCallback(async () => {
    const fd = new FormData();
    fd.set('id', draftId);
    fd.set('title', title);
    fd.set('body', body);
    fd.set('language', language);
    const result = await saveMessageDraftAction(null, fd);
    if (result.ok && result.data) {
      setDraftId(result.data.id);
      showToast(t('draftSaved'));
    } else if (!result.ok) {
      showToast(t((result.error ?? 'unknownError') as never));
    }
  }, [draftId, title, body, language, t, showToast]);

  const archiveToLibrary = useCallback(async () => {
    const fd = new FormData();
    fd.set('title', title);
    fd.set('body', body);
    fd.set('language', language);
    fd.set('category', category);
    const result = await saveLibraryItemAction(null, fd);
    if (result.ok) showToast(t('libraryArchived'));
    else if (!result.ok) showToast(t((result.error ?? 'unknownError') as never));
  }, [title, body, language, category, t, showToast]);

  const saveAudience = useCallback(async () => {
    const fd = buildFilterFd(filter);
    fd.set('name', audienceName);
    fd.set('audienceId', '');
    const result = await saveAudienceAction(null, fd);
    if (result.ok) {
      showToast(t('audienceSaved'));
      router.refresh();
    } else if (!result.ok) {
      showToast(t((result.error ?? 'unknownError') as never));
    }
  }, [filter, audienceName, t, showToast, router]);

  const runSearch = useCallback(async () => {
    setSearching(true);
    const fd = new FormData();
    fd.set('query', searchQuery);
    const result = await searchPatientsAction(null, fd);
    if (result.ok && result.data) setSearchResults(result.data);
    setSearching(false);
  }, [searchQuery]);

  const applyAudience = useCallback((row: AudienceRow) => {
    const raw = normalizeRecipientFilter(row.filterJson);
    setFilter({
      tags: raw.tags.join(', '),
      tagMode: raw.tagMode,
      serviceIds: raw.serviceIds,
      serviceCategory: raw.serviceCategory,
      language: raw.language,
      lastVisitFrom: raw.lastVisitFrom,
      lastVisitTo: raw.lastVisitTo,
      boughtServiceFrom: raw.boughtServiceFrom,
      boughtServiceTo: raw.boughtServiceTo,
      upcoming: raw.upcoming,
      birthdayMonth: String(raw.birthdayMonth),
      presetRange: raw.presetRange,
      handPicked: raw.handPickedIds.map((id) => ({ id, fullName: id, phoneE164: '' })),
    });
    setPreview(null);
  }, []);

  const loadDraft = useCallback((draft: DraftRow) => {
    setDraftId(draft.id);
    setBody(draft.body);
    setLanguage(draft.language);
    setTemplateKey('CUSTOM');
    setLibraryItemId('');
    setTitle(draft.title ?? '');
  }, []);

  const loadLibraryItem = useCallback((item: LibraryRow) => {
    setBody(item.body);
    setLanguage(item.language);
    setTemplateKey('CUSTOM');
    setLibraryItemId(item.id);
    setTitle((current) => current || item.title);
  }, []);

  const goStep = useCallback(
    (next: number) => {
      setStep(next);
      if (next === 3 && deliveryMode === 'GROUP') void runPreview();
    },
    [deliveryMode, runPreview],
  );

  if (!canSend) return <p className="card p-5 text-sm text-slate-600">{t('campaignsForbidden')}</p>;

  const groupBlocked = (preview?.groupBlockReasons.length ?? 0) > 0;
  const autoTitle = title.trim() || body.split('\n')[0]?.trim().slice(0, 120) || 'Message';

  return (
    <form action={formAction} className="space-y-4">
      {/* Step rail */}
      <ol className="flex flex-wrap gap-2 text-xs">
        {[1, 2, 3].map((index) => (
          <li key={index}>
            <button
              type="button"
              className={step === index ? 'btn-primary' : 'btn-secondary'}
              onClick={() => goStep(index)}
            >
              {t(index === 1 ? 'filterTitle' : index === 2 ? 'messageBody' : 'deliverySection')}
            </button>
          </li>
        ))}
      </ol>

      {/* ------------------------------------------------------------------ */}
      {/* Step 1 — Audience                                                    */}
      {/* ------------------------------------------------------------------ */}
      <div hidden={step !== 1} className="space-y-4">
        <fieldset className="card space-y-3 p-4">
          <legend className="px-1 text-sm font-semibold text-slate-700">{t('filterTitle')}</legend>

          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterPresetRange')}</label>
              <select
                className="input-field"
                name="presetRange"
                value={filter.presetRange}
                onChange={(event) => changeFilter({ presetRange: event.target.value })}
              >
                <option value="">{t('presetNone')}</option>
                <option value="last30">{t('presetLast30')}</option>
                <option value="last90">{t('presetLast90')}</option>
                <option value="last180">{t('presetLast180')}</option>
                <option value="thisYear">{t('presetThisYear')}</option>
              </select>
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterLanguage')}</label>
              <select
                className="input-field"
                name="language"
                value={filter.language}
                onChange={(event) => changeFilter({ language: event.target.value })}
              >
                <option value="">{t('any')}</option>
                <option value="en">English</option>
                <option value="am">አማርኛ</option>
              </select>
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterTags')}</label>
              <input
                className="input-field"
                name="tags"
                value={filter.tags}
                placeholder={t('tagsHint')}
                onChange={(event) => changeFilter({ tags: event.target.value })}
              />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterTagMode')}</label>
              <select
                className="input-field"
                name="tagMode"
                value={filter.tagMode}
                onChange={(event) => changeFilter({ tagMode: event.target.value })}
              >
                <option value="ANY">{t('tagAny')}</option>
                <option value="ALL">{t('tagAll')}</option>
              </select>
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterLastVisitFrom')}</label>
              <input
                className="input-field"
                type="date"
                name="lastVisitFrom"
                value={filter.lastVisitFrom}
                onChange={(event) => changeFilter({ lastVisitFrom: event.target.value })}
              />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterLastVisitTo')}</label>
              <input
                className="input-field"
                type="date"
                name="lastVisitTo"
                value={filter.lastVisitTo}
                onChange={(event) => changeFilter({ lastVisitTo: event.target.value })}
              />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterBoughtServiceFrom')}</label>
              <input
                className="input-field"
                type="date"
                name="boughtServiceFrom"
                value={filter.boughtServiceFrom}
                onChange={(event) => changeFilter({ boughtServiceFrom: event.target.value })}
              />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterBoughtServiceTo')}</label>
              <input
                className="input-field"
                type="date"
                name="boughtServiceTo"
                value={filter.boughtServiceTo}
                onChange={(event) => changeFilter({ boughtServiceTo: event.target.value })}
              />
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterUpcoming')}</label>
              <select
                className="input-field"
                name="upcoming"
                value={filter.upcoming}
                onChange={(event) => changeFilter({ upcoming: event.target.value })}
              >
                <option value="ANY">{t('any')}</option>
                <option value="HAS">{t('hasUpcoming')}</option>
                <option value="NONE">{t('noUpcoming')}</option>
              </select>
            </div>
            <div>
              <label className="text-xs uppercase text-slate-500">{t('filterCategory')}</label>
              <select
                className="input-field"
                name="serviceCategory"
                value={filter.serviceCategory}
                onChange={(event) => changeFilter({ serviceCategory: event.target.value })}
              >
                <option value="">{t('any')}</option>
                {SERVICE_CATEGORIES.map((value) => (
                  <option key={value} value={value}>
                    {ts(CATEGORY_LABEL_KEYS[value] as never)}
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
                value={filter.birthdayMonth}
                onChange={(event) => changeFilter({ birthdayMonth: event.target.value })}
              />
            </div>
          </div>

          {/* Service picker */}
          <div>
            <label className="text-xs uppercase text-slate-500">{t('filterServices')}</label>
            <select
              className="input-field"
              value=""
              onChange={(event) => {
                const id = event.target.value;
                if (!id) return;
                changeFilter({
                  serviceIds: filter.serviceIds.includes(id)
                    ? filter.serviceIds.filter((value) => value !== id)
                    : [...filter.serviceIds, id],
                });
              }}
            >
              <option value="">{t('filterServices')}</option>
              {services.map((service) => (
                <option key={service.id} value={service.id}>
                  {service.name}
                </option>
              ))}
            </select>
            {filter.serviceIds.length > 0 ? (
              <ul className="mt-2 flex flex-wrap gap-1">
                {filter.serviceIds.map((id) => (
                  <li
                    key={id}
                    className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
                  >
                    {services.find((service) => service.id === id)?.name ?? id}
                    <button
                      type="button"
                      className="ml-1 text-red-500"
                      onClick={() =>
                        changeFilter({ serviceIds: filter.serviceIds.filter((v) => v !== id) })
                      }
                    >
                      ×
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>

          {/* Hand-picked patients */}
          <div className="border-t border-slate-100 pt-3">
            <label className="text-xs uppercase text-slate-500">{t('filterHandPicked')}</label>
            <p className="text-xs text-slate-500">{t('handPickedHint')}</p>
            <div className="mt-2 flex gap-2">
              <input
                className="input-field"
                value={searchQuery}
                placeholder={t('searchPlaceholder')}
                onChange={(event) => setSearchQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault();
                    void runSearch();
                  }
                }}
              />
              <button type="button" className="btn-secondary" onClick={() => void runSearch()}>
                {searching ? tc('loading') : t('preview')}
              </button>
            </div>
            {searchResults.length > 0 ? (
              <ul className="mt-2 max-h-40 overflow-auto rounded-md border border-slate-100">
                {searchResults.map((patient) => {
                  const already = filter.handPicked.some((picked) => picked.id === patient.id);
                  return (
                    <li
                      key={patient.id}
                      className="flex items-center justify-between px-2 py-1 text-sm"
                    >
                      <span>
                        {patient.fullName}{' '}
                        <span className="text-xs text-slate-500">{patient.phoneE164}</span>
                      </span>
                      <button
                        type="button"
                        className={already ? 'btn-secondary' : 'btn-primary'}
                        onClick={() =>
                          changeFilter({
                            handPicked: already
                              ? filter.handPicked.filter((picked) => picked.id !== patient.id)
                              : [...filter.handPicked, patient],
                          })
                        }
                      >
                        {already ? t('pickRemove') : t('pickAdd')}
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : null}
            {filter.handPicked.length > 0 ? (
              <p className="mt-2 text-xs text-slate-600">
                {t('handPickedCount', { count: filter.handPicked.length })}
              </p>
            ) : null}
          </div>

        </fieldset>

        {/* Saved audiences */}
        {audiences.length > 0 || canManageAudiences ? (
          <div className="card space-y-3 p-4">
            <div className="flex flex-wrap items-end gap-2">
              <div className="flex-1">
                <label className="text-xs uppercase text-slate-500">{t('audiences')}</label>
                <select
                  className="input-field"
                  value=""
                  onChange={(event) => {
                    const row = audiences.find((item) => item.id === event.target.value);
                    if (row) applyAudience(row);
                  }}
                >
                  <option value="">{t('applyAudience')}</option>
                  {audiences.map((row) => (
                    <option key={row.id} value={row.id}>
                      {row.name}
                    </option>
                  ))}
                </select>
              </div>
              {canManageAudiences ? (
                <>
                  <div className="flex-1">
                    <label className="text-xs uppercase text-slate-500">{t('audienceName')}</label>
                    <input
                      className="input-field"
                      value={audienceName}
                      onChange={(event) => setAudienceName(event.target.value)}
                    />
                  </div>
                  <button
                    type="button"
                    className="btn-secondary"
                    disabled={!audienceName.trim()}
                    onClick={() => void saveAudience()}
                  >
                    {t('saveAudience')}
                  </button>
                </>
              ) : null}
            </div>
          </div>
        ) : null}

        <div className="card space-y-2 p-4 text-sm">
          <button type="button" className="btn-primary" onClick={() => void runPreview()}>
            {t('preview')}
          </button>
          {preview ? (
            <>
              <p>{t('previewMatched', { count: preview.matched })}</p>
              <p>{t('previewEligible', { count: preview.eligible })}</p>
              {preview.overCap ? (
                <p className="font-medium text-red-600">{t('overCap')}</p>
              ) : null}
            </>
          ) : null}
        </div>

        <div className="flex justify-end">
          <button type="button" className="btn-primary" onClick={() => goStep(2)}>
            {tc('next')}
          </button>
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* Step 2 — Message                                                     */}
      {/* ------------------------------------------------------------------ */}
      <div hidden={step !== 2} className="space-y-4">
        <div className="card space-y-3 p-4">
          <div>
            <label className="text-xs uppercase text-slate-500">{t('campaignTitleLabel')}</label>
            <input
              className="input-field"
              name="title"
              maxLength={120}
              value={title}
              placeholder={autoTitle}
              onChange={(event) => setTitle(event.target.value)}
            />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="text-xs uppercase text-slate-500">{t('template')}</label>
              <select
                className="input-field"
                name="templateKey"
                value={templateKey}
                onChange={(event) => {
                  setTemplateKey(event.target.value);
                  if (event.target.value !== 'CUSTOM') setLibraryItemId('');
                }}
              >
                {seenTemplates.map((row) => (
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
            <select
              className="input-field"
              name="fixedLanguage"
              value={fixedLanguage}
              onChange={(event) => setFixedLanguage(event.target.value)}
            >
              <option value="en">English</option>
              <option value="am">አማርኛ</option>
            </select>
          ) : (
            <input type="hidden" name="fixedLanguage" value={fixedLanguage} />
          )}

          {templateKey === 'CUSTOM' ? (
            <div className="space-y-2">
              <label className="text-xs uppercase text-slate-500">{t('messageBody')}</label>
              <textarea
                className="input-field min-h-32"
                name="customBody"
                value={body}
                onChange={(event) => {
                  setBody(event.target.value);
                  setLibraryItemId('');
                }}
                placeholder={t('messageBody')}
              />
              <div className="flex flex-wrap items-center gap-2">
                <select
                  className="input-field w-auto"
                  value={language}
                  onChange={(event) => setLanguage(event.target.value)}
                >
                  <option value="en">English</option>
                  <option value="am">አማርኛ</option>
                </select>
                <button type="button" className="btn-secondary" onClick={() => void saveDraft()}>
                  {t('saveDraft')}
                </button>
                {canManageLibrary ? (
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => void archiveToLibrary()}
                  >
                    {t('archiveToLibrary')}
                  </button>
                ) : null}
                <select
                  className="input-field w-auto"
                  value={category}
                  onChange={(event) => setCategory(event.target.value)}
                >
                  {(['REMINDER', 'GREETING', 'ANNOUNCEMENT', 'FOLLOWUP', 'OTHER'] as const).map(
                    (value) => (
                      <option key={value} value={value}>
                        {t(LIBRARY_CATEGORY_LABEL_KEYS[value] as never)}
                      </option>
                    ),
                  )}
                </select>
              </div>
            </div>
          ) : (
            <input type="hidden" name="customBody" value="" />
          )}
          <input type="hidden" name="libraryItemId" value={libraryItemId} />
          <input type="hidden" name="expiresAt" value="" />
        </div>

        {/* Reuse from library */}
        <div className="card space-y-2 p-4">
          <label className="text-xs uppercase text-slate-500">{t('libraryTitle')}</label>
          {library.length > 0 ? (
            <ul className="divide-y divide-slate-100">
              {library.slice(0, 8).map((item) => (
                <li key={item.id} className="flex items-start justify-between gap-2 py-2 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-800">{item.title}</p>
                    <p className="truncate text-xs text-slate-500">{item.body}</p>
                  </div>
                  <button type="button" className="btn-secondary" onClick={() => loadLibraryItem(item)}>
                    {t('libraryUse')}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500">{t('libraryNoItems')}</p>
          )}
        </div>

        {/* Drafts */}
        <div className="card space-y-2 p-4">
          <label className="text-xs uppercase text-slate-500">{t('draftsTitle')}</label>
          {drafts.length > 0 ? (
            <ul className="divide-y divide-slate-100">
              {drafts.slice(0, 8).map((draft) => (
                <li key={draft.id} className="flex items-start justify-between gap-2 py-2 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-800">{draft.title ?? '—'}</p>
                    <p className="truncate text-xs text-slate-500">{draft.body}</p>
                  </div>
                  <button type="button" className="btn-secondary" onClick={() => loadDraft(draft)}>
                    {t('loadDraft')}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500">{t('noDrafts')}</p>
          )}
        </div>

        <div className="flex justify-between">
          <button type="button" className="btn-secondary" onClick={() => goStep(1)}>
            {tc('back')}
          </button>
          <button type="button" className="btn-primary" onClick={() => goStep(3)}>
            {tc('next')}
          </button>
        </div>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* Step 3 — How to send                                                 */}
      {/* ------------------------------------------------------------------ */}
      <div hidden={step !== 3} className="space-y-4">
        <fieldset className="card space-y-3 p-4">
          <legend className="px-1 text-sm font-semibold text-slate-700">{t('deliverySection')}</legend>

          <label className="flex items-start gap-2 rounded-md border border-slate-200 p-3">
            <input
              type="radio"
              name="deliveryMode"
              value="INDIVIDUAL"
              checked={deliveryMode === 'INDIVIDUAL'}
              onChange={() => setDeliveryMode('INDIVIDUAL')}
            />
            <span>
              <span className="font-medium text-slate-800">{t('deliveryIndividual')}</span>
              <span className="block text-xs text-slate-500">{t('deliveryIndividualDesc')}</span>
            </span>
          </label>

          <label className="flex items-start gap-2 rounded-md border border-slate-200 p-3">
            <input
              type="radio"
              name="deliveryMode"
              value="GROUP"
              checked={deliveryMode === 'GROUP'}
              onChange={() => {
                setDeliveryMode('GROUP');
                void runPreview();
              }}
            />
            <span>
              <span className="font-medium text-slate-800">{t('deliveryGroup')}</span>
              <span className="block text-xs text-slate-500">{t('deliveryGroupDesc')}</span>
            </span>
          </label>

          {deliveryMode === 'GROUP' ? (
            preview && preview.groupBlockReasons.length > 0 ? (
              <div className="space-y-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                <p className="font-medium">{t('groupBlockTitle')}</p>
                <p className="text-xs">{t('groupBlockIntro')}</p>
                <ul className="list-inside list-disc text-xs">
                  {preview.groupBlockReasons.map((reason) => (
                    <li key={reason}>
                      {t(GROUP_BLOCK_LABEL_KEYS[reason] as never)}
                    </li>
                  ))}
                </ul>
                {allowGroupOverride ? null : (
                  <p className="text-xs">{t('groupBlockOverrideHint')}</p>
                )}
              </div>
            ) : preview ? (
              <p className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
                {t('groupSafe')}
              </p>
            ) : null
          ) : null}

          {deliveryMode === 'GROUP' ? (
            <label className="flex items-start gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                name="acknowledgedGroupWarning"
                value="true"
                checked={ack}
                onChange={(event) => setAck(event.target.checked)}
              />
              {t('groupAckText')}
            </label>
          ) : (
            <input type="hidden" name="acknowledgedGroupWarning" value="false" />
          )}
        </fieldset>

        <div className="card space-y-1 p-4 text-sm text-slate-600">
          <p>{t('sendFromClinicPhone')}</p>
        </div>

        {state && !state.ok ? (
          <p className="text-sm text-red-600">{state.error ?? tc('unknownError')}</p>
        ) : null}

        <div className="flex justify-between">
          <button type="button" className="btn-secondary" onClick={() => goStep(2)}>
            {tc('back')}
          </button>
          <button
            type="submit"
            className="btn-primary"
            disabled={
              pending ||
              (deliveryMode === 'GROUP' && (!preview || groupBlocked || !ack))
            }
          >
            {pending ? tc('saving') : t(createLabelKey as never)}
          </button>
        </div>
      </div>

      {toast ? (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs text-white shadow-lg">
          {toast}
        </div>
      ) : null}
    </form>
  );
}
