'use client';

import { useCallback, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { CampaignQueueItem } from '../queries';
import { MESSAGE_STATUS_LABEL_KEYS } from '../constants';
import { buildSmsLink, detectPlatform } from '../provider';
import {
  acknowledgeGroupCopyAction,
  cancelCampaignAction,
  confirmMessageSentAction,
  openMessageTaskAction,
  skipMessageTaskAction,
} from '../actions';

type Props = {
  campaignId: string;
  items: CampaignQueueItem[];
  canManage: boolean;
  progress: { sent: number; pending: number; skipped: number; total: number };
};

export default function CampaignQueue({ campaignId, items, canManage, progress }: Props) {
  const t = useTranslations('messages');
  const router = useRouter();
  const [active, setActive] = useState<CampaignQueueItem | null>(null);
  const [message, setMessage] = useState('');
  const [skipOpen, setSkipOpen] = useState(false);
  const [skipReason, setSkipReason] = useState('');
  const [copyOpen, setCopyOpen] = useState(false);
  const [ack, setAck] = useState(false);
  const [toast, setToast] = useState('');

  const pending = useMemo(
    () => items.filter((item) => item.status === 'PENDING' || item.status === 'OPENED'),
    [items],
  );

  const send = useCallback(async (task: CampaignQueueItem) => {
    const fd = new FormData();
    fd.set('id', task.id);
    const result = await openMessageTaskAction(null, fd);
    if (!result.ok) {
      setToast(result.error ?? 'unknownError');
      return;
    }
    const data = result.data;
    if (!data) return;
    setActive(task);
    setMessage(data.renderedMessage);
    if (data.status !== 'BLOCKED') {
      window.location.href = buildSmsLink(
        data.phoneE164,
        data.renderedMessage,
        detectPlatform(navigator.userAgent),
      );
    }
  }, []);

  const confirm = useCallback(async () => {
    if (!active) return;
    const fd = new FormData();
    fd.set('id', active.id);
    await confirmMessageSentAction(null, fd);
    setActive(null);
    router.refresh();
  }, [active, router]);

  const doSkip = useCallback(async () => {
    if (!active || !skipReason.trim()) return;
    const fd = new FormData();
    fd.set('id', active.id);
    fd.set('reason', skipReason.trim());
    await skipMessageTaskAction(null, fd);
    setActive(null);
    setSkipOpen(false);
    setSkipReason('');
    router.refresh();
  }, [active, skipReason, router]);

  const copyAll = useCallback(async () => {
    const numbers = pending.map((item) => item.patientPhoneE164).join(', ');
    try {
      await navigator.clipboard.writeText(numbers);
    } catch {
      setToast(numbers);
    }
    const fd = new FormData();
    fd.set('campaignId', campaignId);
    await acknowledgeGroupCopyAction(null, fd);
    setCopyOpen(false);
    setAck(false);
    setToast(t('copied'));
    window.setTimeout(() => setToast(''), 2000);
  }, [pending, campaignId, t]);

  const cancel = useCallback(async () => {
    const fd = new FormData();
    fd.set('id', campaignId);
    await cancelCampaignAction(null, fd);
    router.refresh();
  }, [campaignId, router]);

  const pct = progress.total ? Math.round((progress.sent / progress.total) * 100) : 0;

  return (
    <div className="space-y-4">
      <div className="card space-y-2 p-4">
        <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
          <div className="h-full bg-emerald-500" style={{ width: `${pct}%` }} />
        </div>
        <p className="text-xs text-slate-600">
          {t('progress', { sent: progress.sent, total: progress.total, skipped: progress.skipped })}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          className="btn-primary"
          disabled={pending.length === 0}
          onClick={() => pending[0] && void send(pending[0])}
        >
          {t('sendNext')}
        </button>
        <button
          type="button"
          className="btn-secondary"
          disabled={pending.length === 0}
          onClick={() => setCopyOpen(true)}
        >
          {t('copyAllNumbers')}
        </button>
        {canManage ? (
          <button
            type="button"
            className="btn-secondary text-red-600"
            onClick={() => void cancel()}
          >
            {t('cancelRemaining')}
          </button>
        ) : null}
      </div>

      <ul className="card divide-y divide-slate-100">
        {items.map((item) => (
          <li key={item.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
            <div className="min-w-0 flex-1">
              <span className="font-medium text-slate-900">{item.patientName}</span>{' '}
              <span className="text-xs text-slate-500">{item.patientPhoneE164}</span>
              <p className="truncate text-xs text-slate-600">{item.renderedMessage}</p>
            </div>
            <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
              {t(MESSAGE_STATUS_LABEL_KEYS[item.status] as never)}
            </span>
            {item.status === 'PENDING' || item.status === 'OPENED' ? (
              <button type="button" className="btn-primary" onClick={() => void send(item)}>
                {t('send')}
              </button>
            ) : null}
          </li>
        ))}
      </ul>

      {active ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">{active.patientName}</h3>
            <p className="whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm text-slate-700">
              {message}
            </p>
            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn-primary" onClick={() => void confirm()}>
                {t('yesSent')}
              </button>
              <button type="button" className="btn-secondary" onClick={() => setSkipOpen(true)}>
                {t('skip')}
              </button>
              <button type="button" className="btn-secondary" onClick={() => setActive(null)}>
                {t('close')}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {skipOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-sm space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <input
              className="input-field"
              placeholder={t('skipReason')}
              value={skipReason}
              onChange={(event) => setSkipReason(event.target.value)}
            />
            <div className="flex justify-end gap-2">
              <button type="button" className="btn-secondary" onClick={() => setSkipOpen(false)}>
                {t('close')}
              </button>
              <button
                type="button"
                className="btn-primary"
                disabled={!skipReason.trim()}
                onClick={() => void doSkip()}
              >
                {t('skip')}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {copyOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">{t('copyAllNumbers')}</h3>
            <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-800">{t('groupWarning')}</p>
            <label className="flex items-start gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={ack}
                onChange={(event) => setAck(event.target.checked)}
              />
              {t('acknowledge')}
            </label>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn-secondary" onClick={() => setCopyOpen(false)}>
                {t('close')}
              </button>
              <button
                type="button"
                className="btn-primary"
                disabled={!ack}
                onClick={() => void copyAll()}
              >
                {t('copyAllNumbers')}
              </button>
            </div>
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
