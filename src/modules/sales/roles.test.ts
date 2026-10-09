import { describe, expect, it } from 'vitest';
import {
  canApproveVoid,
  canRequestVoid,
  canSeeAllSales,
  canViewRevenue,
  canVoidDirectly,
} from './roles';

describe('sales role predicates', () => {
  it('shows revenue only to the OWNER', () => {
    expect(canViewRevenue('OWNER')).toBe(true);
    expect(canViewRevenue('RECEPTION')).toBe(false);
    expect(canViewRevenue('STAFF')).toBe(false);
  });

  it('lets only the OWNER approve or void directly', () => {
    expect(canApproveVoid('OWNER')).toBe(true);
    expect(canApproveVoid('RECEPTION')).toBe(false);
    expect(canVoidDirectly('OWNER')).toBe(true);
    expect(canVoidDirectly('RECEPTION')).toBe(false);
  });

  it('lets OWNER and RECEPTION request a void, never STAFF', () => {
    expect(canRequestVoid('OWNER')).toBe(true);
    expect(canRequestVoid('RECEPTION')).toBe(true);
    expect(canRequestVoid('STAFF')).toBe(false);
  });

  it('scopes sales visibility to the OWNER', () => {
    expect(canSeeAllSales('OWNER')).toBe(true);
    expect(canSeeAllSales('RECEPTION')).toBe(false);
    expect(canSeeAllSales('STAFF')).toBe(false);
  });
});
