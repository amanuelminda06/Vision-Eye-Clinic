import { describe, expect, it } from 'vitest';
import {
  APPT_ROLES,
  APPT_OWNER_ROLES,
  canManageAppointments,
  canOverrideTerminalTransition,
  FOLLOW_UP_ROLES,
  REQUEST_ROLES,
} from './roles';

describe('appointments role access', () => {
  it('appointments, requests and follow-ups are OWNER + RECEPTION', () => {
    expect(APPT_ROLES).toEqual(['OWNER', 'RECEPTION']);
    expect(REQUEST_ROLES).toEqual(['OWNER', 'RECEPTION']);
    expect(FOLLOW_UP_ROLES).toEqual(['OWNER', 'RECEPTION']);
  });

  it('STAFF is denied every appointment capability', () => {
    expect(APPT_ROLES).not.toContain('STAFF');
    expect(canManageAppointments('STAFF')).toBe(false);
    expect(canManageAppointments('OWNER')).toBe(true);
    expect(canManageAppointments('RECEPTION')).toBe(true);
  });

  it('only the OWNER overrides terminal status changes', () => {
    expect(canOverrideTerminalTransition('OWNER')).toBe(true);
    expect(canOverrideTerminalTransition('RECEPTION')).toBe(false);
    expect(canOverrideTerminalTransition('STAFF')).toBe(false);
    expect(APPT_OWNER_ROLES).toEqual(['OWNER']);
  });
});
