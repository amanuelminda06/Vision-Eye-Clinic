'use client';

import { useTranslations } from 'next-intl';

/** Action-level error keys that live under the `hr.*` message namespace. */
const HR_ERRORS = new Set([
  'shiftOverlap',
  'leaveOverlap',
  'cannotDecideOwnLeave',
  'leaveNotPending',
  'cannotCancel',
  'staffRequired',
]);

/** Global keys shared with every module (under `common.*`). */
const COMMON_ERRORS = new Set([
  'forbidden',
  'unknownError',
  'validationError',
  'passwordChangeRequired',
]);

/** Renders an HR ActionResult error as a translated alert (unknown → nothing). */
export default function HrError({ error }: { error?: string | null }) {
  const th = useTranslations('hr');
  const tc = useTranslations('common');
  if (!error) return null;
  const message = HR_ERRORS.has(error)
    ? th(error as never)
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
