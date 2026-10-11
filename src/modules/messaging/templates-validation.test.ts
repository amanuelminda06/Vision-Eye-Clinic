import { describe, expect, it } from 'vitest';
import { scanSensitiveContent, validateMessageBody } from './templates';

describe('validateMessageBody', () => {
  it('rejects empty bodies', () => {
    const result = validateMessageBody('   ');
    expect(result.ok).toBe(false);
    expect(result.error).toBe('messageRequired');
  });

  it('rejects bodies over the campaign length cap', () => {
    const result = validateMessageBody('x'.repeat(1001));
    expect(result.ok).toBe(false);
    expect(result.error).toBe('messageTooLong');
  });

  it('rejects unknown variables but is fine with known ones', () => {
    expect(validateMessageBody('Hello {name}, see you on {date}').ok).toBe(true);
    const result = validateMessageBody('Hello {does_not_exist}');
    expect(result.ok).toBe(false);
    expect(result.error).toBe('unknownVariable');
    expect(result.unknown).toContain('does_not_exist');
  });

  it('passes clean bodies and reports sensitive terms as warnings', () => {
    const result = validateMessageBody('Dear {name}, come for your appointment.');
    expect(result.ok).toBe(true);
    expect(result.sensitive).toEqual([]);

    const flagged = validateMessageBody('Dear {name}, your cataract surgery costs 5000 birr.');
    expect(flagged.ok).toBe(true);
    expect(flagged.sensitive).toContain('cataract');
    expect(flagged.sensitive).toContain('surgery');
  });
});

describe('scanSensitiveContent', () => {
  it("matches case-insensitively (Latin and Ge'ez)", () => {
    expect(scanSensitiveContent('You have GLAUCOMA')).toContain('glaucoma');
    expect(scanSensitiveContent('ዋጋው ብር ነው')).toEqual(
      expect.arrayContaining(['ዋጋ', 'ብር']),
    );
  });

  it('returns empty for clean text', () => {
    expect(scanSensitiveContent('Please come for a check-up.')).toEqual([]);
  });
});