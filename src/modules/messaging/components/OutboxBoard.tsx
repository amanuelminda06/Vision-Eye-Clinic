'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslations } from 'next-intl';
import type { MessageTaskRow } from '../queries';
import {
  MESSAGE_SOURCE_LABEL_KEYS,
  MESSAGE_STATUS_LABEL_KEYS,
  TEMPLATE_LABEL_KEYS,
  BLOCKED_REASON_LABEL_KEYS,
  type BlockedReason,
} from '../constants';
import { buildSmsLink, detectPlatform } from '../provider';
import {
  confirmMessageSentAction,
  openMessageTaskAction,
  postponeMessageTaskAction,
  skipMessageTaskAction,
  editMessageTaskAction,
} from '../actions';

type Props = {
  groups: {
    dueNow: MessageTaskRow[];
    laterToday: MessageTaskRow[];
    overdue: MessageTaskRow[];
    doneToday: MessageTaskRow[];
    blocked: MessageTaskRow[];
  };
  timeZone: string;
};

function timeLabel(date: Date | string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone,
  }).format(new Date(date));
}

export default function OutboxBoard({ groups, timeZone }: Props) {
  const t = useTranslations('messages');
  const [active, setActive] = useState<MessageTaskRow | null>(null);
  const [message, setMessage] = useState('');
  const [awaitingReturn, setAwaitingReturn] = useState(false);
  const [skipOpen, setSkipOpen] = useState(false);
  const [skipReason, setSkipReason] = useState('');
  const [editOpen, setEditOpen] = useState(false);
  const [editText, setEditText] = useState('');
  const [toast, setToast] = useState('');

  const queue = useMemo(
    () => [...groups.overdue, ...groups.dueNow, ...groups.laterToday],
    [groups.overdue, groups.dueNow, groups.laterToday],
  );

  const copy = useCallback(
    async (text: string) => {
      try {
        await navigator.clipboard.writeText(text);
        setToast(t('copied'));
        window.setTimeout(() => setToast(''), 1500);
      } catch {
        setToast(text);
      }
    },
    [t],
  );

  // Ask "did you send it?" when the tab regains focus while a link is open.
  useEffect(() => {
    if (!awaitingReturn || !active) return;
    const onVisible = () => {
      if (document.visibilityState === 'visible') setAwaitingReturn(false);
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [awaitingReturn, active]);

  const startSend = useCallback(async (task: MessageTaskRow) => {
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
    if (data.status === 'BLOCKED') {
      setAwaitingReturn(false);
      return;
    }
    const platform = detectPlatform(navigator.userAgent);
    const href = buildSmsLink(data.phoneE164, data.renderedMessage, platform);
    window.location.href = href;
    setAwaitingReturn(true);
  }, []);

  const confirm = useCallback(async () => {
    if (!active) return;
    const fd = new FormData();
    fd.set('id', active.id);
    await confirmMessageSentAction(null, fd);
    setActive(null);
    setAwaitingReturn(false);
    window.location.reload();
  }, [active]);

  const postpone = useCallback(async () => {
    if (!active) return;
    const fd = new FormData();
    fd.set('id', active.id);
    await postponeMessageTaskAction(null, fd);
    setActive(null);
    setAwaitingReturn(false);
  }, [active]);

  const doSkip = useCallback(async () => {
    if (!active || !skipReason.trim()) return;
    const fd = new FormData();
    fd.set('id', active.id);
    fd.set('reason', skipReason.trim());
    const result = await skipMessageTaskAction(null, fd);
    if (!result.ok) {
      setToast(result.error ?? 'unknownError');
      return;
    }
    setActive(null);
    setSkipOpen(false);
    setSkipReason('');
    window.location.reload();
  }, [active, skipReason]);

  const doEdit = useCallback(async () => {
    if (!active || !editText.trim()) return;
    const fd = new FormData();
    fd.set('id', active.id);
    fd.set('message', editText);
    const result = await editMessageTaskAction(null, fd);
    if (!result.ok) {
      setToast(result.error ?? 'unknownError');
      return;
    }
    const updated = result.data?.message;
    if (!updated) return;
    setMessage(updated);
    setActive((prev) => (prev ? { ...prev, renderedMessage: updated } : prev));
    setEditOpen(false);
  }, [active, editText]);

  const renderRow = (
    task: MessageTaskRow,
    tone: 'overdue' | 'due' | 'later' | 'done' | 'blocked',
  ) => {
    const toneClass =
      tone === 'overdue'
        ? 'border-l-4 border-red-400 bg-red-50/40'
        : tone === 'due'
          ? 'border-l-4 border-amber-400 bg-amber-50/40'
          : 'border-l-4 border-transparent';
    return (
      <li
        key={task.id}
        className={`flex flex-wrap items-center gap-3 px-3 py-2 text-sm ${toneClass}`}
      >
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="font-medium text-slate-900">{task.patientName}</span>
            <span className="text-xs text-slate-500">{task.patientPhoneE164}</span>
            <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
              {t(MESSAGE_STATUS_LABEL_KEYS[task.status] as never)}
            </span>
            <span className="text-[11px] text-slate-400">
              {t(MESSAGE_SOURCE_LABEL_KEYS[task.sourceType] as never)} ·{' '}
              {t(TEMPLATE_LABEL_KEYS[task.templateKey] as never)}
            </span>
          </div>
          <p className="mt-0.5 truncate text-xs text-slate-600">{task.renderedMessage}</p>
          {task.status === 'BLOCKED' && task.blockedReason ? (
            <p className="mt-0.5 text-xs font-medium text-red-600">
              {t(BLOCKED_REASON_LABEL_KEYS[task.blockedReason as BlockedReason] as never)}
            </p>
          ) : null}
        </div>
        <span className="text-xs text-slate-500">
          {t('due')} {timeLabel(task.dueAt, timeZone)}
        </span>
        {task.status === 'PENDING' || task.status === 'OPENED' ? (
          <div className="flex gap-1">
            <button
              type="button"
              className="btn-secondary"
              onClick={() => void copy(task.renderedMessage)}
            >
              {t('copyMessage')}
            </button>
            <button
              type="button"
              className="btn-secondary"
              onClick={() => {
                setActive(task);
                setMessage(task.renderedMessage);
              }}
            >
              {t('open')}
            </button>
            <button type="button" className="btn-primary" onClick={() => void startSend(task)}>
              {t('send')}
            </button>
          </div>
        ) : null}
      </li>
    );
  };

  const sections: Array<
    [string, MessageTaskRow[], 'overdue' | 'due' | 'later' | 'done' | 'blocked']
  > = [
    ['groupOverdue', groups.overdue, 'overdue'],
    ['groupDueNow', groups.dueNow, 'due'],
    ['groupLaterToday', groups.laterToday, 'later'],
    ['groupDoneToday', groups.doneToday, 'done'],
    ['groupBlocked', groups.blocked, 'blocked'],
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          className="btn-primary"
          disabled={queue.length === 0}
          onClick={() => queue[0] && void startSend(queue[0])}
        >
          {t('sendNext')}
        </button>
        <span className="text-xs text-slate-500">{t('sendFromClinicPhone')}</span>
      </div>

      {sections.map(([labelKey, rows, tone]) =>
        rows.length ? (
          <section key={labelKey} className="card">
            <h2 className="border-b border-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">
              {t(labelKey as never)} ({rows.length})
            </h2>
            <ul className="divide-y divide-slate-100">
              {rows.map((task) => renderRow(task, tone))}
            </ul>
          </section>
        ) : null,
      )}

      {queue.length === 0 && groups.doneToday.length === 0 && groups.blocked.length === 0 ? (
        <p className="card p-5 text-sm text-slate-600">{t('noMessages')}</p>
      ) : null}

      {active ? (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center">
          <div className="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">{active.patientName}</h3>
            <p className="whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm text-slate-700">
              {message}
            </p>

            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn-secondary" onClick={() => void copy(message)}>
                {t('copyMessage')}
              </button>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => void copy(active.patientPhoneE164)}
              >
                {t('copyNumber')}
              </button>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  setEditText(message);
                  setEditOpen(true);
                }}
              >
                {t('edit')}
              </button>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  const platform = detectPlatform(navigator.userAgent);
                  window.location.href = buildSmsLink(active.patientPhoneE164, message, platform);
                  setAwaitingReturn(true);
                }}
              >
                {t('openLink')}
              </button>
            </div>

            <div className="rounded-md border border-slate-200 p-3">
              <p className="text-sm font-medium text-slate-800">{t('confirmDone')}</p>
              <div className="mt-2 flex flex-wrap gap-2">
                <button type="button" className="btn-primary" onClick={() => void confirm()}>
                  {t('yesSent')}
                </button>
                <button type="button" className="btn-secondary" onClick={() => void postpone()}>
                  {t('notYet')}
                </button>
                <button type="button" className="btn-secondary" onClick={() => setSkipOpen(true)}>
                  {t('skip')}
                </button>
              </div>
            </div>

            <div className="flex justify-end">
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
            <h3 className="text-base font-semibold text-slate-900">{t('skip')}</h3>
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

      {editOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
            <h3 className="text-base font-semibold text-slate-900">{t('editMessage')}</h3>
            <textarea
              className="input-field min-h-28"
              value={editText}
              onChange={(event) => setEditText(event.target.value)}
            />
            <div className="flex justify-end gap-2">
              <button type="button" className="btn-secondary" onClick={() => setEditOpen(false)}>
                {t('close')}
              </button>
              <button type="button" className="btn-primary" onClick={() => void doEdit()}>
                {t('save')}
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
