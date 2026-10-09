/**
 * Pure audit helpers: secret redaction + object diffing (unit tested).
 * writeAudit() stores ONLY changed fields (rule 4).
 */

const REDACTED = '[REDACTED]';

const SECRET_KEYS = new Set([
  'password',
  'passwordhash',
  'password_hash',
  'newpassword',
  'oldpassword',
  'secret',
  'token',
  'authtoken',
  'apisecret',
]);

function isSecretKey(key: string): boolean {
  return SECRET_KEYS.has(key.toLowerCase().replace(/[^a-z_]/g, ''));
}

/** Deep-copies a value, replacing any secret-looking field with '[REDACTED]'. */
export function redact<T>(value: T): T {
  if (Array.isArray(value)) {
    return value.map((item) => redact(item)) as unknown as T;
  }
  if (value && typeof value === 'object' && !(value instanceof Date)) {
    const out: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(value as Record<string, unknown>)) {
      out[key] = isSecretKey(key) ? REDACTED : redact(val);
    }
    return out as unknown as T;
  }
  return value;
}

function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (a instanceof Date && b instanceof Date) return a.getTime() === b.getTime();
  if (Array.isArray(a) && Array.isArray(b)) {
    return a.length === b.length && a.every((item, i) => deepEqual(item, b[i]));
  }
  if (a && b && typeof a === 'object' && typeof b === 'object') {
    const keysA = Object.keys(a as object);
    const keysB = Object.keys(b as object);
    if (keysA.length !== keysB.length) return false;
    return keysA.every((key) =>
      deepEqual((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key]),
    );
  }
  return false;
}

export type ObjectDiff = {
  /** Changed fields only: { field: oldValue } */
  old: Record<string, unknown>;
  /** Changed fields only: { field: newValue } */
  new: Record<string, unknown>;
};

/**
 * Shallow-key diff (top-level fields, deep value comparison).
 * Both sides are redacted for secrets.
 */
export function diffObjects(before: unknown, after: unknown): ObjectDiff {
  const oldOut: Record<string, unknown> = {};
  const newOut: Record<string, unknown> = {};
  if (!before || !after || typeof before !== 'object' || typeof after !== 'object') {
    return { old: oldOut, new: newOut };
  }

  const beforeRec = before as Record<string, unknown>;
  const afterRec = after as Record<string, unknown>;
  const keys = new Set([...Object.keys(beforeRec), ...Object.keys(afterRec)]);

  for (const key of keys) {
    if (!deepEqual(beforeRec[key], afterRec[key])) {
      if (key in beforeRec) oldOut[key] = beforeRec[key];
      if (key in afterRec) newOut[key] = afterRec[key];
    }
  }
  return { old: redact(oldOut), new: redact(newOut) };
}

export function hasChanges(diff: ObjectDiff): boolean {
  return Object.keys(diff.old).length > 0 || Object.keys(diff.new).length > 0;
}
