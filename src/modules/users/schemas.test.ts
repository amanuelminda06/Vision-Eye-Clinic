import { describe, expect, it } from 'vitest';
import { userCreateSchema, userPasswordSetSchema, userUpdateSchema } from './schemas';

describe('userPasswordSetSchema', () => {
  it('accepts matching passwords', () => {
    const result = userPasswordSetSchema.safeParse({
      password: 'a-strong-passphrase',
      confirmPassword: 'a-strong-passphrase',
    });
    expect(result.success).toBe(true);
  });

  it('rejects a missing password', () => {
    const result = userPasswordSetSchema.safeParse({ password: '', confirmPassword: '' });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path[0] === 'password')).toBe(true);
    }
  });

  it('flags a confirmation mismatch on confirmPassword', () => {
    const result = userPasswordSetSchema.safeParse({
      password: 'a-strong-passphrase',
      confirmPassword: 'a-different-passphrase',
    });
    expect(result.success).toBe(false);
    if (!result.success) {
      const issue = result.error.issues.find((i) => i.path[0] === 'confirmPassword');
      expect(issue?.message).toBe('passwordMismatch');
    }
  });
});

describe('userCreateSchema / userUpdateSchema', () => {
  const valid = {
    name: 'Abebe Kebede',
    email: 'abebe@example.com',
    role: 'STAFF',
    staffId: undefined,
  };

  it('requires a password only on create', () => {
    expect(userCreateSchema.safeParse({ ...valid, password: 'x' }).success).toBe(true);
    expect(userCreateSchema.safeParse(valid).success).toBe(false);
    expect(userUpdateSchema.safeParse(valid).success).toBe(true);
  });

  it('normalizes email to lowercase', () => {
    const parsed = userCreateSchema.parse({ ...valid, email: 'ABEBE@EXAMPLE.COM', password: 'x' });
    expect(parsed.email).toBe('abebe@example.com');
  });

  it('rejects an unknown role', () => {
    expect(userCreateSchema.safeParse({ ...valid, role: 'ADMIN', password: 'x' }).success).toBe(
      false,
    );
  });
});
