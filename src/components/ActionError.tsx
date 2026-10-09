'use client';

import { useTranslations } from 'next-intl';

/** Error keys that live under the `common.*` message namespace. */
const KNOWN_KEYS = new Set([
  'forbidden',
  'unknownError',
  'validationError',
  'passwordChangeRequired',
  'cannotDeactivateSelf',
  'saved',
]);

/**
 * Renders the global (non-field) error of an ActionResult as a translated
 * alert. Unknown/empty keys render nothing.
 */
export default function ActionError({ error }: { error?: string | null }) {
  const t = useTranslations('common');
  if (!error || !KNOWN_KEYS.has(error)) return null;
  return (
    <p role="alert" className="text-sm font-medium text-red-600">
      {t(error as never)}
    </p>
  );
}
