'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { useTranslations } from 'next-intl';

type SearchHit = { id: string; title: string; subtitle: string; href: string };
type SearchResponse = Record<'patients' | 'sales' | 'appointments', SearchHit[]>;
type GroupKey = keyof SearchResponse;

const GROUPS: GroupKey[] = ['patients', 'sales', 'appointments'];

/** Debounced global search in the header (server route is role-gated). */
export default function GlobalSearch() {
  const t = useTranslations('app');
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResponse | null>(null);
  const [fetching, setFetching] = useState(false);
  const rootRef = useRef<HTMLDivElement | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    function onClick(event: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) setOpen(false);
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, []);

  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    const value = query.trim();
    if (value.length < 2) return;
    timerRef.current = setTimeout(() => {
      setFetching(true);
      void fetch(`/api/search?q=${encodeURIComponent(value)}`)
        .then((response) => {
          if (!response.ok) throw new Error('search failed');
          return response.json() as Promise<SearchResponse>;
        })
        .then((data) => {
          setResults(data);
          setOpen(true);
        })
        .catch(() => setResults(null))
        .finally(() => setFetching(false));
    }, 200);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [query]);

  const hasQuery = query.trim().length >= 2;
  const total = results
    ? results.patients.length + results.sales.length + results.appointments.length
    : 0;
  const showDropdown = open && hasQuery && results;

  return (
    <div ref={rootRef} className="relative w-48 sm:w-64">
      <input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        onFocus={() => {
          setOpen(true);
        }}
        placeholder={t('searchPlaceholder')}
        aria-label={t('searchPlaceholder')}
        className="input-field"
      />
      {showDropdown ? (
        <div className="absolute left-0 right-0 top-full z-50 mt-1 max-h-96 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
          {total === 0 ? (
            <div className="px-3 py-2 text-sm text-slate-500">{t('searchEmpty')}</div>
          ) : (
            <>
              <div className="border-b border-slate-100 px-3 py-1.5 text-xs text-slate-400">
                {fetching ? t('searchSearching') : t('searchCount', { count: total })}
              </div>
              {GROUPS.map((group) =>
                results[group].length ? (
                  <div key={group} className="py-1">
                    <div className="px-3 py-0.5 text-xs font-medium uppercase tracking-wide text-slate-400">
                      {t(`searchGroup${group[0].toUpperCase()}${group.slice(1)}` as never)}
                    </div>
                    {results[group].map((hit) => (
                      <Link
                        key={`${group}-${hit.id}`}
                        href={hit.href}
                        onClick={() => {
                          setOpen(false);
                          setQuery('');
                        }}
                        className="block px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                      >
                        <span className="block font-medium text-slate-800">{hit.title}</span>
                        <span className="block truncate text-xs text-slate-400">{hit.subtitle}</span>
                      </Link>
                    ))}
                  </div>
                ) : null,
              )}
            </>
          )}
        </div>
      ) : null}
    </div>
  );
}