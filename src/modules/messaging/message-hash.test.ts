import { describe, expect, it } from 'vitest';
import { messageBodyHash, normalizeMessageBody, titleFromBody } from './message-hash';

describe('normalizeMessageBody', () => {
  it('trims and collapses whitespace and lowercases', () => {
    expect(normalizeMessageBody('  Hello   አለም  ')).toBe('hello አለም');
    expect(normalizeMessageBody('')).toBe('');
  });
});

describe('messageBodyHash', () => {
  it('is stable across runs', () => {
    const body = 'Dear {{first_name}}, please come on {{date}}';
    expect(messageBodyHash(body)).toBe(messageBodyHash(body));
  });

  it('ignores case / spacing differences (duplicate guard)', () => {
    expect(messageBodyHash('Hello   World')).toBe(messageBodyHash('hello world'));
  });

  it('is deterministic and 8 hex chars', () => {
    expect(messageBodyHash('x')).toMatch(/^[0-9a-f]{8}$/);
  });

  it('distinguishes different bodies (FNV not collisions here)', () => {
    expect(messageBodyHash('Message A')).not.toBe(messageBodyHash('Message B'));
  });
});

describe('titleFromBody', () => {
  it('returns the first line when short enough', () => {
    expect(titleFromBody('Appointment reminder\nnext line')).toBe('Appointment reminder');
  });

  it('truncates long first lines with an ellipsis', () => {
    const long = 'x'.repeat(100);
    expect(titleFromBody(long)).toHaveLength(40);
    expect(titleFromBody(long).endsWith('…')).toBe(true);
  });

  it('returns empty for an empty body', () => {
    expect(titleFromBody('')).toBe('');
  });
});