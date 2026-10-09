import { describe, expect, it } from 'vitest';
import { isSimilarName, levenshtein, normalizeNameForKey } from './duplicates';

describe('normalizeNameForKey', () => {
  it('lowercases and strips punctuation and spaces', () => {
    expect(normalizeNameForKey('  Mekdes,  Alemu. ')).toBe('mekdesalemu');
  });

  it('keeps non-latin letters (Amharic)', () => {
    expect(normalizeNameForKey('ሰላም ገብረ')).toBe('ሰላምገብረ');
  });

  it('treats identical spellings as equal keys', () => {
    expect(normalizeNameForKey('Mekdes Alemu')).toBe(normalizeNameForKey('MEKDES ALEMU'));
  });
});

describe('levenshtein', () => {
  it('is 0 for equal strings', () => {
    expect(levenshtein('abebe', 'abebe')).toBe(0);
  });

  it('counts single edits', () => {
    expect(levenshtein('marta', 'martha')).toBe(1);
    expect(levenshtein('mekdes', 'mekides')).toBe(1);
  });

  it('is symmetric', () => {
    expect(levenshtein('girma', 'german')).toBe(levenshtein('german', 'girma'));
  });
});

describe('isSimilarName', () => {
  it('flags a one-letter spelling difference', () => {
    expect(isSimilarName('Mekdes Alemu', 'Mekides Alemu')).toBe(true);
  });

  it('ignores case, punctuation and extra spaces', () => {
    expect(isSimilarName('Mekdes Alemu', '  mekdes, alemu!')).toBe(true);
  });

  it('does not flag completely different names', () => {
    expect(isSimilarName('Mekdes Alemu', 'Bekele Wolde')).toBe(false);
    expect(isSimilarName('Almaz Tesfaye', 'Solomon Tadesse')).toBe(false);
  });

  it('does not flag very short names that differ', () => {
    expect(isSimilarName('Sara', 'Mary')).toBe(false);
  });

  it('allows a small difference in long names', () => {
    expect(isSimilarName('Woinishet Getu', 'Woinshet Getu')).toBe(true);
    expect(isSimilarName('Woinishet Getu', 'Woinishet Girmay')).toBe(false);
  });
});
