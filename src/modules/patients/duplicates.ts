/** Pure helpers for duplicate-patient detection (unit-tested). */

/**
 * Normalizes a full name for comparison: unicode-normalized, lowercased,
 * letters only (spacing/punctuation removed) — works for Latin and Ethiopic.
 */
export function normalizeNameForKey(name: string): string {
  return name
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[^\p{L}]/gu, '');
}

/** Classic Levenshtein edit distance (two-row implementation). */
export function levenshtein(a: string, b: string): number {
  if (a === b) return 0;
  if (!a.length) return b.length;
  if (!b.length) return a.length;

  let prev = new Array<number>(b.length + 1);
  let curr = new Array<number>(b.length + 1);
  for (let j = 0; j <= b.length; j++) prev[j] = j;

  for (let i = 1; i <= a.length; i++) {
    curr[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      curr[j] = Math.min(curr[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
    }
    [prev, curr] = [curr, prev];
  }
  return prev[b.length];
}

/**
 * True when two names are VERY similar (likely the same person):
 * identical after normalization, or within a small edit distance that
 * scales with the length of the longer name.
 */
export function isSimilarName(a: string, b: string): boolean {
  const na = normalizeNameForKey(a);
  const nb = normalizeNameForKey(b);
  if (!na || !nb) return false;
  if (na === nb) return true;
  const longest = Math.max(na.length, nb.length);
  if (longest < 5) return false;
  const threshold = longest >= 10 ? 2 : 1;
  return levenshtein(na, nb) <= threshold;
}
