import { getTranslations } from 'next-intl/server';
import { listPatientMessages } from '../queries';
import {
  BLOCKED_REASON_LABEL_KEYS,
  MESSAGE_SOURCE_LABEL_KEYS,
  MESSAGE_STATUS_LABEL_KEYS,
  TEMPLATE_LABEL_KEYS,
  type BlockedReason,
} from '../constants';
import { formatInTz } from '@/lib/datetime';

/** Read-only message/status history for the patient detail "Messages" tab. */
export default async function PatientMessagesList({
  patientId,
  timeZone,
}: {
  patientId: string;
  timeZone: string;
}) {
  const t = await getTranslations('messages');
  const rows = await listPatientMessages(patientId);

  if (rows.length === 0) {
    return <p className="text-sm text-slate-600">{t('noMessages')}</p>;
  }

  return (
    <ul className="divide-y divide-slate-100">
      {rows.map((row) => (
        <li key={row.id} className="py-3">
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="font-medium text-slate-800">
              {t(TEMPLATE_LABEL_KEYS[row.templateKey] as never)}
            </span>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
              {t(MESSAGE_STATUS_LABEL_KEYS[row.status] as never)}
            </span>
            <span className="text-[11px] text-slate-400">
              {t(MESSAGE_SOURCE_LABEL_KEYS[row.sourceType] as never)}
            </span>
            <span className="ml-auto text-xs text-slate-500">
              {formatInTz(row.handledAt ?? row.dueAt, timeZone)}
            </span>
          </div>
          <p className="mt-1 whitespace-pre-wrap text-xs text-slate-600">{row.renderedMessage}</p>
          {row.status === 'BLOCKED' && row.blockedReason ? (
            <p className="mt-0.5 text-xs font-medium text-red-600">
              {t(BLOCKED_REASON_LABEL_KEYS[row.blockedReason as BlockedReason] as never)}
            </p>
          ) : null}
          {row.status === 'SKIPPED' && row.skipReason ? (
            <p className="mt-0.5 text-xs text-slate-500">{row.skipReason}</p>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
