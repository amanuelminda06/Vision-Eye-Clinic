import { describe, expect, it } from 'vitest';
import { hasRole } from '@/lib/roles';
import { PATIENT_ROLES } from './roles';

describe('PATIENT_ROLES (access control for the patients module)', () => {
  it('allows OWNER and RECEPTION', () => {
    expect(hasRole('OWNER', PATIENT_ROLES)).toBe(true);
    expect(hasRole('RECEPTION', PATIENT_ROLES)).toBe(true);
  });

  it('denies STAFF', () => {
    expect(hasRole('STAFF', PATIENT_ROLES)).toBe(false);
    expect(PATIENT_ROLES).not.toContain('STAFF');
  });
});
