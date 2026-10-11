'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { DraftRow } from '../queries';
import { deleteMessageDraftAction } from '../actions';

export default function DraftsBoard({ drafts }: { drafts: DraftRow[] }) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const router = useRouter();
  const [toast, setToast] = useState('');

  function load(draft: DraftRow) {
    router.push(`/messages/new?draft=${draft.id}`);
  }

  async function remove(id: string) {
    const fd = new FormData();
    fd.set('id', id);
    const result = await deleteMessageDraftAction(null, fd);
    if (result.ok) {
      setToast(tc('deleted'));
      window.setTimeout(() => setToast(''), 2000);
      router.refresh();
    } else {
      setToast(result.error ?? tc('unknownError'));
      window.setTimeout(() => setToast(''), 3000);
    }
  }

  if (drafts.length === 0) {
    return <div className="card px-4 py-6 text-sm text-slate-500">{t('draftsNoDrafts')}</div>;
  }

  return (
    <div className="space-y-4">
      <ul className="card divide-y divide-slate-100">
        {drafts.map((draft) => (
          <li key={draft.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-slate-900">
                {draft.title || t('draftsUntitled')}{' '}
                <span className="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                  {draft.language}
                </span>
              </p>
              <p className="line-clamp-2 whitespace-pre-wrap text-sm text-slate-600">{draft.body}</p>
            </div>
            <div className="flex gap-2">
              <button type="button" className="btn-secondary" onClick={() => load(draft)}>
                {t('loadDraft')}
              </button>
              <button
                type="button"
                className="btn-secondary text-red-600"
                onClick={() => void remove(draft.id)}
              >
                {tc('delete')}
              </button>
            </div>
          </li>
        ))}
      </ul>
      {toast ? (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs text-white shadow-lg">
          {toast}
        </div>
      ) : null}
    </div>
  );
}
