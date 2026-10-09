'use client';

import { useTranslations } from 'next-intl';
import { useState } from 'react';
import ConvertRequestDialog, { type ConvertDoctorOption } from './ConvertRequestDialog';
import RequestQuickActions from './RequestQuickActions';

type Props = {
  requestId: string;
  patientId: string | null;
  status: string;
  doctors: ConvertDoctorOption[];
};

/** Request row controls: convert (dialog) + contacted/decline/spam. */
export default function RequestRowActions({ requestId, patientId, status, doctors }: Props) {
  const t = useTranslations('requests');
  const [convertOpen, setConvertOpen] = useState(false);

  const convertible = status === 'NEW' || status === 'CONTACTED';

  return (
    <div className="flex flex-wrap items-center gap-2">
      {convertible ? (
        <button type="button" className="btn-primary" onClick={() => setConvertOpen(true)}>
          {t('convert')}
        </button>
      ) : null}
      <RequestQuickActions requestId={requestId} status={status} />
      <ConvertRequestDialog
        open={convertOpen}
        onClose={() => setConvertOpen(false)}
        requestId={requestId}
        patientId={patientId}
        doctors={doctors}
      />
    </div>
  );
}
