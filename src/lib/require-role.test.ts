import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('next/navigation', () => ({
  redirect: (url: string) => {
    const error: Error & { isRedirect?: boolean } = new Error(`REDIRECT:${url}`);
    error.isRedirect = true;
    throw error;
  },
}));

vi.mock('@/auth', () => ({
  auth: vi.fn(),
  loginPath: '/login/secret-path',
}));

import { auth } from '@/auth';
import { AuthorizationError, requireAuth, requirePageRole, requireRole } from './require-role';

const mockedAuth = auth as unknown as ReturnType<typeof vi.fn>;

function withSession(user: Record<string, unknown> | null) {
  mockedAuth.mockResolvedValue(user ? { user } : null);
}

function owner(overrides: Record<string, unknown> = {}) {
  return {
    id: 'user-1',
    email: 'owner@clinic.test',
    name: 'Owner',
    role: 'OWNER',
    mustChangePassword: false,
    ...overrides,
  };
}

beforeEach(() => {
  mockedAuth.mockReset();
});

describe('requireRole', () => {
  it('redirects anonymous requests to the secret login path', async () => {
    withSession(null);
    await expect(requireRole(['OWNER'])).rejects.toThrow('REDIRECT:/login/secret-path');
  });

  it('allows a matching role', async () => {
    withSession(owner());
    const user = await requireRole(['OWNER']);
    expect(user.id).toBe('user-1');
    expect(user.role).toBe('OWNER');
    expect(user.email).toBe('owner@clinic.test');
  });

  it('denies a non-matching role with FORBIDDEN', async () => {
    withSession(owner({ role: 'STAFF' }));
    await expect(requireRole(['OWNER'])).rejects.toMatchObject({
      name: 'AuthorizationError',
      code: 'FORBIDDEN',
    });
  });

  it('blocks while a password change is still pending', async () => {
    withSession(owner({ mustChangePassword: true }));
    await expect(requireRole(['OWNER'])).rejects.toMatchObject({
      code: 'PASSWORD_CHANGE_REQUIRED',
    });
  });
});

describe('requireAuth', () => {
  it('stays reachable while a password change is pending', async () => {
    withSession(owner({ mustChangePassword: true }));
    const user = await requireAuth();
    expect(user.id).toBe('user-1');
    expect(user.mustChangePassword).toBe(true);
  });

  it('still redirects anonymous requests', async () => {
    withSession(null);
    await expect(requireAuth()).rejects.toThrow('REDIRECT:/login/secret-path');
  });
});

describe('requirePageRole', () => {
  it('maps FORBIDDEN to the /403 page', async () => {
    withSession(owner({ role: 'RECEPTION' }));
    await expect(requirePageRole(['OWNER'])).rejects.toThrow('REDIRECT:/403');
  });

  it('maps a pending password change to /change-password', async () => {
    withSession(owner({ mustChangePassword: true }));
    await expect(requirePageRole(['OWNER'])).rejects.toThrow('REDIRECT:/change-password');
  });

  it('passes through a successful check', async () => {
    withSession(owner());
    const user = await requirePageRole(['OWNER']);
    expect(user.role).toBe('OWNER');
  });

  it('never converts redirect() errors into AuthorizationError', async () => {
    withSession(null);
    await expect(requirePageRole(['OWNER'])).rejects.toThrow('REDIRECT:/login/secret-path');
  });
});

describe('AuthorizationError', () => {
  it('carries the code', () => {
    const error = new AuthorizationError('FORBIDDEN');
    expect(error.code).toBe('FORBIDDEN');
    expect(error.name).toBe('AuthorizationError');
  });
});
