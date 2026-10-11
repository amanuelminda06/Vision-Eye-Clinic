/**
 * Pure helpers for the Message Library: normalising message bodies into a
 * stable hash (for duplicate detection) and deriving a default title.
 * No DB / no React — unit-tested directly.
 */

/** Trims, collapses runs of whitespace, and lowercases a message body. */
export function normalizeMessageBody(body: string): string {
  return body.trim().replace(/\s+/g, ' ').toLowerCase();
}

/**
 * Stable, dependency-free hash (FNV-1a, 32-bit) over the normalised body.
 * Uniqueness is enforced per language via `@@unique([normalizedHash, language])`,
 * so collisions are only a theoretical concern at this scale.
 */
export function messageBodyHash(body: string): string {
  const normalized = normalizeMessageBody(body);
  let hash = 0x811c9dc5;
  for (let i = 0; i < normalized.length; i += 1) {
    hash ^= normalized.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
  }
  return (hash >>> 0).toString(16).padStart(8, '0');
}

const MAX_AUTO_TITLE = 40;

/** Auto-title from the first line of the body (library "archive" default). */
export function titleFromBody(body: string): string {
  const firstLine = body.split('\n').map((line) => line.trim()).find(Boolean) ?? '';
  if (firstLine.length <= MAX_AUTO_TITLE) return firstLine;
  return `${firstLine.slice(0, MAX_AUTO_TITLE - 1).trimEnd()}…`;
}
