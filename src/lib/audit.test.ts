import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Prisma } from '@prisma/client';

const { createMock } = vi.hoisted(() => ({ createMock: vi.fn() }));
vi.mock('@/lib/prisma', () => ({
  prisma: { auditLog: { create: createMock } },
}));

const { headersMock } = vi.hoisted(() => ({ headersMock: vi.fn() }));
vi.mock('next/headers', () => ({
  headers: () => headersMock(),
}));

import { writeAudit, clientIp } from './audit';

describe('audit clientIp', () => {
  beforeEach(() => vi.clearAllMocks());

  it('uses the first x-forwarded-for value', async () => {
    headersMock.mockResolvedValue({
      get: (k: string) => (k === 'x-forwarded-for' ? '1.2.3.4, 5.6.7.8' : null),
    });
    await expect(clientIp()).resolves.toBe('1.2.3.4');
  });

  it('falls back to x-real-ip', async () => {
    headersMock.mockResolvedValue({ get: (k: string) => (k === 'x-real-ip' ? '1.2.3.4' : null) });
    await expect(clientIp()).resolves.toBe('1.2.3.4');
  });

  it('returns null when headers throw (e.g. outside request scope)', async () => {
    headersMock.mockRejectedValue(new Error('no request'));
    await expect(clientIp()).resolves.toBeNull();
  });
});

describe('writeAudit', () => {
  beforeEach(() => vi.clearAllMocks());

  it('writes a row when there is no old/new (plain event)', async () => {
    createMock.mockResolvedValue({});
    await writeAudit({ user: { id: 'u1', name: 'A' }, entity: 'Sale', entityId: 's1', action: 'VIEW' });
    expect(createMock).toHaveBeenCalledTimes(1);
    const data = createMock.mock.calls[0][0].data;
    expect(data.userId).toBe('u1');
    expect(data.entity).toBe('Sale');
    expect(data.action).toBe('VIEW');
    expect(data.oldJson).toBe(Prisma.DbNull);
    expect(data.newJson).toBe(Prisma.DbNull);
  });

  it('stores a redacted diff of old/new', async () => {
    createMock.mockResolvedValue({});
    await writeAudit({
      user: { id: 'u1' },
      entity: 'User',
      entityId: 'u2',
      action: 'UPDATED',
      old: { name: 'Old', passwordHash: 'secret-hash' },
      new: { name: 'New', passwordHash: 'new-secret-hash' },
      ip: '9.9.9.9',
    });
    const data = createMock.mock.calls[0][0].data;
    expect(data.oldJson).toEqual({ name: 'Old', passwordHash: '[REDACTED]' });
    expect(data.newJson).toEqual({ name: 'New', passwordHash: '[REDACTED]' });
    expect(data.ip).toBe('9.9.9.9');
  });

  it('stores DbNull when there are no changes (old === new)', async () => {
    createMock.mockResolvedValue({});
    await writeAudit({
      user: { id: 'u1' },
      entity: 'User',
      entityId: 'u3',
      action: 'TOUCHED',
      old: { name: 'Same' },
      new: { name: 'Same' },
    });
    const data = createMock.mock.calls[0][0].data;
    expect(data.oldJson).toBe(Prisma.DbNull);
    expect(data.newJson).toBe(Prisma.DbNull);
  });

  it('omits userId for anonymous events and nulls when not linked', async () => {
    createMock.mockResolvedValue({});
    await writeAudit({ user: null, entity: 'Login', entityId: '-', action: 'LOGIN_FAILED' });
    expect(createMock.mock.calls[0][0].data.userId).toBeNull();
  });

  it('never throws when the underlying write fails (audit must not break the op)', async () => {
    createMock.mockRejectedValue(new Error('db down'));
    await expect(
      writeAudit({ entity: 'X', entityId: 'y', action: 'Z', user: { id: 'u' } }),
    ).resolves.toBeUndefined();
  });
});