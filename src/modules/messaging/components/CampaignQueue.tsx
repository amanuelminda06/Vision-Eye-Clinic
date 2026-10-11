'use client';

import { useCallback, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import type { CampaignBatchRow, CampaignQueueItem } from '../queries';
import { BATCH_STATUS_LABEL_KEYS, MESSAGE_STATUS_LABEL_KEYS } from '../constants';
import { buildGroupSmsLink, buildSmsLink, detectPlatform } from '../provider';
import {
  acknowledgeGroupCopyAction,
  cancelCampaignAction,
  confirmMessageSentAction,
  openMessageTaskAction,
  setCampaignBatchStatusAction,
  skipMessageTaskAction,
} from '../actions';

type Props = {
  campaignId: string;
  items: CampaignQueueItem[];
  canManage: boolean;
  deliveryMode: 'INDIVIDUAL' | 'GROUP';
  batches: CampaignBatchRow[];
  progress: { sent: number; pending: number; skipped: number; total: number };
};

export default function CampaignQueue({
  campaignId,
  items,
  canManage,
  deliveryMode,
  batches,
  progress,
}: Props) {
  const t = useTranslations('messages');
  const tc = useTranslations('common');
  const router = useRouter();
  const [active, setActive] = useState<CampaignQueueItem | null>(null);
  const [activeBatch, setActiveBatch] = useState<CampaignBatchRow | null>(null);
  const [message, setMessage] = useState('');
  const [skipOpen, setSkipOpen] = useState(false);
  const [skipReason, setSkipReason] = useState('');
  const [copyOpen, setCopyOpen] = useState(false);
  const [ack, setAck] = useState(false);
  const [toast, setToast] = useState('');

  const isGroup = deliveryMode === 'GROUP';

  const pending = useMemo(
    () => items.filter((item) => item.status === 'PENDING' || item.status === 'OPENED'),
    [items],
  );

  const pendingBatches = useMemo(
    () => batches.filter((batch) => batch.status === 'PENDING'),
    [batches],
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

  const sendBatch = useCallback((batch: CampaignBatchRow) => {
    setActiveBatch(batch);
    window.location.href = buildGroupSmsLink(
      batch.phones,
      batch.message,
      detectPlatform(navigator.userAgent),
    );
  }, []);

  const handleBatch = useCallback(
    async (status: 'SENT' | 'SKIPPED') => {
      if (!activeBatch) return;
      if (status === 'SKIPPED' && !skipReason.trim()) return;
      const fd = new FormData();
      fd.set('batchId', activeBatch.id);
      fd.set('status', status);
      fd.set('reason', skipReason.trim());
      const result = await setCampaignBatchStatusAction(null, fd);
      if (!result.ok) {
        setToast(result.error ?? tc('unknownError'));
        return;
      }
      setActiveBatch(null);
      setSkipOpen(false);
      setSkipReason('');
      router.refresh();
    },
    [activeBatch, skipReason, router, tc],
  );

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
        {isGroup ? (
          <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-800">{t('groupBatchNote')}</p>
        ) : null}
      </div>

      <div className="flex flex-wrap gap-2">
        {isGroup ? (
          <button
            type="button"
            className="btn-primary"
            disabled={pendingBatches.length === 0}
            onClick={() => pendingBatches[0] && sendBatch(pendingBatches[0])}
          >
            {t('sendNextBatch')}
          </button>
        ) : (
          <button
            type="button"
            className="btn-primary"
            disabled={pending.length === 0}
            onClick={() => pending[0] && void send(pending[0])}
          >
            {t('sendNext')}
          </button>
        )}
        {!isGroup ? (
          <button
            type="button"
            className="btn-secondary"
            disabled={pending.length === 0}
            onClick={() => setCopyOpen(true)}
          >
            {t('copyAllNumbers')}
          </button>
        ) : null}
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

      {isGroup ? (
        <ul className="card divide-y divide-slate-100">
          {batches.length === 0 ? (
            <li className="px-4 py-3 text-sm text-slate-500">{t('noBatches')}</li>
          ) : (
            batches.map((batch) => (
              <li key={batch.id} className="space-y-1 px-4 py-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium text-slate-900">
                    {t('batchLabel', { number: batch.batchNumber, count: batch.size })}
                  </span>
                  <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                    {t(BATCH_STATUS_LABEL_KEYS[batch.status] as never)}
                  </span>
                </div>
                <p className="text-xs text-slate-500">
                  {batch.sample.map((row) => `${row.fullName} ${row.phoneE164}`).join(' · ')}
                  {batch.size > batch.sample.length
                    ? ` · ${t('sampleOf', { shown: batch.sample.length, total: batch.size })}`
                    : ''}
                </p>
                <p className="whitespace-pre-wrap text-xs text-slate-600">{batch.message}</p>
                {batch.status === 'PENDING' ? (
                  <button type="button" className="btn-primary" onClick={() => sendBatch(batch)}>
                    {t('sendThisBatch')}
                  </button>
                ) : null}
              </li>
            ))
          )}
        </ul>
      ) : (
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
      )}

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
                {tc('close')}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {activeBatch ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">
              {t('batchLabel', { number: activeBatch.batchNumber, count: activeBatch.size })}
            </h3>
            <p className="rounded-md bg-amber-50 p-3 text-xs text-amber-800">
              {t('groupBatchConfirm')}
            </p>
            <p className="whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm text-slate-700">
              {activeBatch.message}
            </p>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                className="btn-primary"
                onClick={() => void handleBatch('SENT')}
              >
                {t('yesSent')}
              </button>
              <button type="button" className="btn-secondary" onClick={() => setSkipOpen(true)}>
                {t('skip')}
              </button>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setActiveBatch(null)}
              >
                {tc('close')}
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
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  setSkipOpen(false);
                  setSkipReason('');
                }}
              >
                {tc('close')}
              </button>
              <button
                type="button"
                className="btn-primary"
                disabled={!skipReason.trim()}
                onClick={() =>
                  activeBatch ? void handleBatch('SKIPPED') : void doSkip()
                }
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
                {tc('close')}
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
