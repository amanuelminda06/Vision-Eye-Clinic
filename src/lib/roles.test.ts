import { describe, expect, it } from 'vitest';
import { ALL_ROLES, hasRole, isRole } from './roles';

describe('roles', () => {
  it('lists exactly the three clinic roles', () => {
    expect(ALL_ROLES).toEqual(['OWNER', 'RECEPTION', 'STAFF']);
  });

  it('isRole guards unknown values', () => {
    expect(isRole('OWNER')).toBe(true);
    expect(isRole('SUPERADMIN')).toBe(false);
    expect(isRole(undefined)).toBe(false);
  });

  it('hasRole is null-safe and only matches allowed roles', () => {
    expect(hasRole('OWNER', ['OWNER'])).toBe(true);
    expect(hasRole('STAFF', ['OWNER'])).toBe(false);
    expect(hasRole(null, ['OWNER'])).toBe(false);
    expect(hasRole(undefined, ['OWNER'])).toBe(false);
    expect(hasRole('RECEPTION', ['OWNER', 'RECEPTION'])).toBe(true);
  });
});
