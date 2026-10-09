'use client';

import { useTranslations } from 'next-intl';

/** A plain print button for non-receipt pages (daily summary, etc.). */
export default function PrintPageButton({ labelKey = 'print' }: { labelKey?: string }) {
  const t = useTranslations('common');
  return (
    <button type="button" className="btn-secondary no-print" onClick={() => window.print()}>
      {t(labelKey as 'print')}
    </button>
  );
}