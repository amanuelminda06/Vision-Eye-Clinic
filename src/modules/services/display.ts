/**
 * Pure, locale-aware display helpers for service names and receipt labels.
 * Kept dependency-free so the intent is unit-testable.
 */

type NameSource = { name: string; nameEn: string | null | undefined; nameAm: string | null };

/** Full display name in the given locale (English default). */
export function serviceNameIn(locale: string, source: NameSource): string {
  if (locale === 'am') return source.nameAm ?? source.nameEn ?? source.name;
  return source.nameEn ?? source.name;
}

type ReceiptSource = {
  receiptLabelEn: string | null | undefined;
  receiptLabelAm: string | null | undefined;
  /** Fallback when no label exists for the locale (e.g. the sale snapshot). */
  fallback: string;
};

/**
 * Short receipt label for the given locale. Falls back to the other language,
 * then to `fallback` (the full service name captured at sale time).
 */
export function receiptLabelIn(locale: string, source: ReceiptSource): string {
  if (locale === 'am') return source.receiptLabelAm ?? source.receiptLabelEn ?? source.fallback;
  return source.receiptLabelEn ?? source.receiptLabelAm ?? source.fallback;
}