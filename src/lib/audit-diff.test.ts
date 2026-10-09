import { describe, expect, it } from 'vitest';
import { diffObjects, hasChanges, redact } from './audit-diff';

describe('audit-diff', () => {
  it('redacts secret-looking fields anywhere in the value', () => {
    const value = {
      name: 'Abebe',
      passwordHash: '$argon2id$…',
      nested: { token: 'abc', keep: 'visible' },
      list: [{ password: 'hunter2' }],
    };
    expect(redact(value)).toEqual({
      name: 'Abebe',
      passwordHash: '[REDACTED]',
      nested: { token: '[REDACTED]', keep: 'visible' },
      list: [{ password: '[REDACTED]' }],
    });
  });

  it('stores only changed fields on each side', () => {
    const diff = diffObjects(
      { name: 'Old', email: 'same@x.test', role: 'STAFF' },
      { name: 'New', email: 'same@x.test', role: 'OWNER' },
    );
    expect(diff.old).toEqual({ name: 'Old', role: 'STAFF' });
    expect(diff.new).toEqual({ name: 'New', role: 'OWNER' });
    expect('email' in diff.old).toBe(false);
    expect('email' in diff.new).toBe(false);
    expect(hasChanges(diff)).toBe(true);
  });

  it('reports no changes for identical objects', () => {
    const value = { a: 1, nested: { b: [1, 2] } };
    const diff = diffObjects(value, { a: 1, nested: { b: [1, 2] } });
    expect(hasChanges(diff)).toBe(false);
  });

  it('redacts secrets inside the diff output', () => {
    const diff = diffObjects(
      { passwordHash: 'old-hash', active: true },
      { passwordHash: 'new-hash', active: false },
    );
    expect(diff.old.passwordHash).toBe('[REDACTED]');
    expect(diff.new.passwordHash).toBe('[REDACTED]');
    expect(diff.new.active).toBe(false);
  });

  it('treats a missing field on one side as a change', () => {
    const diff = diffObjects({ staffId: 'staff-1' }, {});
    expect(diff.old).toEqual({ staffId: 'staff-1' });
    expect(diff.new).toEqual({});
    expect(hasChanges(diff)).toBe(true);
  });

  it('compares Dates by value, not reference', () => {
    const diff = diffObjects({ at: new Date('2026-01-01') }, { at: new Date('2026-01-01') });
    expect(hasChanges(diff)).toBe(false);
  });
});
