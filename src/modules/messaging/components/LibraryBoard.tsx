'use client';

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { LIBRARY_CATEGORY_LABEL_KEYS } from '../constants';
import type { LibraryRow } from '../queries';
import { deleteLibraryItemAction, saveLibraryItemAction, toggleLibraryFavoriteAction } from '../actions';

export default function LibraryBoard({
  items,
  canManage,
}: {
  items: LibraryRow[];
  canManage: boolean;
}) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const router = useRouter();

  const [search, setSearch] = useState('');
  const [language, setLanguage] = useState('');
  const [category, setCategory] = useState('');
  const [favorites, setFavorites] = useState(false);

  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [lang, setLang] = useState('en');
  const [cat, setCat] = useState('REMINDER');
  const [toast, setToast] = useState('');

  const show = useMemo(() => {
    const term = search.trim().toLowerCase();
    return items.filter((item) => {
      if (language && item.language !== language) return false;
      if (category && item.category !== category) return false;
      if (favorites && !item.isFavorite) return false;
      if (term && !item.title.toLowerCase().includes(term) && !item.body.toLowerCase().includes(term)) {
        return false;
      }
      return true;
    });
  }, [items, search, language, category, favorites]);

  const flash = (message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 2000);
  };

  async function toggleFavorite(id: string) {
    const fd = new FormData();
    fd.set('id', id);
    await toggleLibraryFavoriteAction(null, fd);
    router.refresh();
  }

  async function archive(id: string) {
    const fd = new FormData();
    fd.set('id', id);
    await deleteLibraryItemAction(null, fd);
    router.refresh();
  }

  function compose(item: LibraryRow) {
    router.push(`/messages/new?library=${item.id}`);
  }

  async function add() {
    const fd = new FormData();
    fd.set('title', title);
    fd.set('body', body);
    fd.set('language', lang);
    fd.set('category', cat);
    const result = await saveLibraryItemAction(null, fd);
    if (result.ok) {
      setBody('');
      setTitle('');
      flash(t('libraryArchived'));
      router.refresh();
    } else if (!result.ok) {
      flash(t((result.error ?? 'unknownError') as never));
    }
  }

  return (
    <div className="space-y-4">
      <div className="card flex flex-wrap items-end gap-2 p-4">
        <div className="min-w-40 flex-1">
          <label className="text-xs uppercase text-slate-500">{t('librarySearch')}</label>
          <input
            className="input-field"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <div>
          <label className="text-xs uppercase text-slate-500">{t('libraryLanguage')}</label>
          <select
            className="input-field"
            value={language}
            onChange={(event) => setLanguage(event.target.value)}
          >
            <option value="">{tc('all')}</option>
            <option value="en">English</option>
            <option value="am">አማርኛ</option>
          </select>
        </div>
        <div>
          <label className="text-xs uppercase text-slate-500">{t('libraryCategory')}</label>
          <select
            className="input-field"
            value={category}
            onChange={(event) => setCategory(event.target.value)}
          >
            <option value="">{tc('all')}</option>
            {(['REMINDER', 'GREETING', 'ANNOUNCEMENT', 'FOLLOWUP', 'OTHER'] as const).map((value) => (
              <option key={value} value={value}>
                {t(LIBRARY_CATEGORY_LABEL_KEYS[value] as never)}
              </option>
            ))}
          </select>
        </div>
        <label className="flex items-center gap-2 pb-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={favorites}
            onChange={(event) => setFavorites(event.target.checked)}
          />
          {t('addToFavorites')}
        </label>
      </div>

      <ul className="card divide-y divide-slate-100">
        {show.length === 0 ? (
          <li className="px-4 py-3 text-sm text-slate-500">{t('libraryNoItems')}</li>
        ) : (
          show.map((item) => (
            <li key={item.id} className="space-y-1 px-4 py-3 text-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-slate-900">
                    {item.title}{' '}
                    <span className="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                      {item.language}
                    </span>
                    <span className="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                      {t(LIBRARY_CATEGORY_LABEL_KEYS[item.category as never] as never)}
                    </span>
                  </p>
                  <p className="whitespace-pre-wrap text-slate-600">{item.body}</p>
                  <p className="text-xs text-slate-400">
                    {t('usedTimes', { count: item.useCount })}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button type="button" className="btn-secondary" onClick={() => void compose(item)}>
                    {t('reuseThis')}
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => void toggleFavorite(item.id)}
                  >
                    {item.isFavorite ? '★' : '☆'} {t('addToFavorites')}
                  </button>
                  {canManage ? (
                    <button
                      type="button"
                      className="btn-secondary text-red-600"
                      onClick={() => void archive(item.id)}
                    >
                      {tc('delete')}
                    </button>
                  ) : null}
                </div>
              </div>
            </li>
          ))
        )}
      </ul>

      {canManage ? (
        <div className="card space-y-3 p-4">
          <label className="text-xs uppercase text-slate-500">{t('libraryItemTitle')}</label>
          <input
            className="input-field"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            maxLength={120}
          />
          <textarea
            className="input-field min-h-24"
            value={body}
            onChange={(event) => setBody(event.target.value)}
            placeholder={t('messageBody')}
          />
          <div className="flex flex-wrap gap-2">
            <select
              className="input-field w-auto"
              value={lang}
              onChange={(event) => setLang(event.target.value)}
            >
              <option value="en">English</option>
              <option value="am">አማርኛ</option>
            </select>
            <select
              className="input-field w-auto"
              value={cat}
              onChange={(event) => setCat(event.target.value)}
            >
              {(['REMINDER', 'GREETING', 'ANNOUNCEMENT', 'FOLLOWUP', 'OTHER'] as const).map(
                (value) => (
                  <option key={value} value={value}>
                    {t(LIBRARY_CATEGORY_LABEL_KEYS[value] as never)}
                  </option>
                ),
              )}
            </select>
            <button
              type="button"
              className="btn-primary"
              disabled={!body.trim()}
              onClick={() => void add()}
            >
              {t('libraryArchive')}
            </button>
          </div>
        </div>
      ) : null}

      {toast ? (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs text-white shadow-lg">
          {toast}
        </div>
      ) : null}
    </div>
  );
}
