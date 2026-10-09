'use client';

import { useTranslations } from 'next-intl';

type Props = {
  /** Route base, e.g. `/api/patients/export`. */
  base: string;
  /** Page query string including the leading `?` (may be empty). */
  query?: string;
};

/**
 * CSV + XLSX download links for an export route. Every download is audited
 * server-side (see `auditExport`) — this component is only the UI.
 */
export default function ExportLinks({ base, query = '' }: Props) {
  const t = useTranslations('export');
  const separator = query ? '&' : '?';

  return (
    <div className="no-print flex items-center gap-1 text-sm">
      {(['csv', 'xlsx'] as const).map((format) => (
        <a
          key={format}
          href={`${base}${query}${separator}format=${format}`}
          className="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900"
          download
        >
          {t(`format${format === 'csv' ? 'Csv' : 'Xlsx'}`)}
        </a>
      ))}
    </div>
  );
}