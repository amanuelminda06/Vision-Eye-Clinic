'use client';

import { useTranslations } from 'next-intl';

/** Action-level error keys that live under the `appointments.*` namespace. */
const APPT_ERRORS = new Set([
  'doctorRequired',
  'notFound',
  'terminalLocked',
  'invalidTransition',
  'cancelReasonRequired',
  'requestAlreadyConverted',
  'patientRequired',
  'patientInactive',
]);

/** Global keys shared with every module (under `common.*`). */
const COMMON_ERRORS = new Set([
  'forbidden',
  'unknownError',
  'validationError',
  'passwordChangeRequired',
]);

/** Renders an appointments ActionResult error as a translated alert. */
export default function AppointmentError({ error }: { error?: string | null }) {
  const ta = useTranslations('appointments');
  const tc = useTranslations('common');
  if (!error) return null;
  const message = APPT_ERRORS.has(error)
    ? ta(error as never)
    : COMMON_ERRORS.has(error)
      ? tc(error as never)
      : null;
  if (!message) return null;
  return (
    <p role="alert" className="text-sm font-medium text-red-600">
      {message}
    </p>
  );
}
