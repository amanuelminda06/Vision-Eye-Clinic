import { describe, it, expect } from 'vitest';
import {
  MIN_PASSWORD_LENGTH,
  passwordError,
  generateTemporaryPassword,
} from './passwords';

describe('password rules', () => {
  it('rejects passwords shorter than the minimum length', () => {
    expect(passwordError('short1'.slice(0, MIN_PASSWORD_LENGTH - 1))).toBe('passwordTooShort');
    expect(passwordError('')).toBe('passwordTooShort');
  });

  it('rejects common passwords', () => {
    expect(passwordError('password123')).toBe('passwordCommon');
    expect(passwordError('Password123')).toBe('passwordCommon');
    expect(passwordError('1234567890')).toBe('passwordCommon');
    expect(passwordError('qwertyuiop')).toBe('passwordCommon');
  });

  it('rejects passwords containing the personal email local part', () => {
    expect(passwordError('abebeKebede2026!', { email: 'abebekebede@clinic.et' })).toBe(
      'passwordPersonal',
    );
    expect(passwordError('clinic-admin-75', { email: 'owner@clinic.et' })).toBeNull();
  });

  it('rejects passwords containing the user name', () => {
    expect(passwordError('hi I am Mulugeta 77', { name: 'Mulugeta' })).toBe('passwordPersonal');
  });

  it('accepts a strong unrelated password', () => {
    expect(passwordError('R7#kQp2!vLmW', { email: 'a@b.et', name: 'x' })).toBeNull();
    expect(passwordError('correct horse battery staple!!')).toBeNull();
  });
});

describe('generateTemporaryPassword', () => {
  it('returns 14 chars from the unambiguous alphabet only', () => {
    const set = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for (let i = 0; i < 50; i++) {
      const pwd = generateTemporaryPassword();
      expect(pwd).toHaveLength(14);
      for (const c of pwd) expect(set).toContain(c);
    }
  });

  it('produces different values across calls', () => {
    expect(generateTemporaryPassword()).not.toBe(generateTemporaryPassword());
  });
});