'use server';

import { hash } from '@node-rs/argon2';
import { Prisma } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate, ValidationError } from '@/lib/errors';
import { generateTemporaryPassword, passwordError } from '@/lib/passwords';
import { userCreateSchema, userUpdateSchema } from './schemas';

function isPrismaError(err: unknown, code: string): boolean {
  return err instanceof Prisma.PrismaClientKnownRequestError && err.code === code;
}

function normalizeStaffId(raw: unknown): string | undefined {
  const value = String(raw ?? '').trim();
  return value || undefined;
}

function readCommonFields(formData: FormData, withPassword: boolean) {
  return {
    name: String(formData.get('name') ?? ''),
    email: String(formData.get('email') ?? ''),
    role: String(formData.get('role') ?? ''),
    staffId: normalizeStaffId(formData.get('staffId')),
    ...(withPassword ? { password: String(formData.get('password') ?? '') } : {}),
  };
}

export async function createUserAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth(['OWNER'], async (actor) => {
    const values = validate(userCreateSchema, readCommonFields(formData, true));

    const strength = passwordError(values.password, { email: values.email, name: values.name });
    if (strength) throw new ValidationError({ password: strength });

    const passwordHash = await hash(values.password);
    let created;
    try {
      created = await prisma.user.create({
        data: {
          name: values.name,
          email: values.email,
          role: values.role,
          staffId: values.staffId ?? null,
          passwordHash,
          mustChangePassword: true,
        },
      });
    } catch (err) {
      if (isPrismaError(err, 'P2002')) throw new ValidationError({ email: 'emailTaken' });
      if (isPrismaError(err, 'P2003')) throw new ValidationError({ staffId: 'unknown' });
      throw err;
    }

    await writeAudit({
      user: actor,
      entity: 'User',
      entityId: created.id,
      action: 'USER_CREATED',
      new: {
        name: values.name,
        email: values.email,
        role: values.role,
        staffId: values.staffId ?? null,
        active: true,
      },
    });

    return ok({ id: created.id });
  });
}

export async function updateUserAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string }>> {
  return withAuth(['OWNER'], async (actor) => {
    const id = String(formData.get('id') ?? '');
    const values = validate(userUpdateSchema, readCommonFields(formData, false));

    const before = await prisma.user.findUnique({
      where: { id },
      select: { name: true, email: true, role: true, staffId: true },
    });
    if (!before) return fail('unknownError');

    let updated;
    try {
      updated = await prisma.user.update({
        where: { id },
        data: {
          name: values.name,
          email: values.email,
          role: values.role,
          staffId: values.staffId ?? null,
        },
      });
    } catch (err) {
      if (isPrismaError(err, 'P2002')) throw new ValidationError({ email: 'emailTaken' });
      if (isPrismaError(err, 'P2003')) throw new ValidationError({ staffId: 'unknown' });
      throw err;
    }

    await writeAudit({
      user: actor,
      entity: 'User',
      entityId: id,
      action: 'USER_UPDATED',
      old: { name: before.name, email: before.email, role: before.role, staffId: before.staffId },
      new: {
        name: updated.name,
        email: updated.email,
        role: updated.role,
        staffId: updated.staffId,
      },
    });

    return ok({ id });
  });
}

export async function setUserActiveAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(['OWNER'], async (actor) => {
    const id = String(formData.get('id') ?? '');
    const active = String(formData.get('active') ?? '') === 'true';

    if (!active && id === actor.id) return fail('cannotDeactivateSelf');

    const before = await prisma.user.findUnique({
      where: { id },
      select: { active: true },
    });
    if (!before) return fail('unknownError');
    if (before.active === active) return ok();

    await prisma.user.update({ where: { id }, data: { active } });
    await writeAudit({
      user: actor,
      entity: 'User',
      entityId: id,
      action: 'USER_STATUS_CHANGED',
      old: { active: before.active },
      new: { active },
    });
    return ok();
  });
}

export async function resetUserPasswordAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ tempPassword: string }>> {
  return withAuth(['OWNER'], async (actor) => {
    const id = String(formData.get('id') ?? '');

    const before = await prisma.user.findUnique({
      where: { id },
      select: { mustChangePassword: true },
    });
    if (!before) return fail('unknownError');

    const tempPassword = generateTemporaryPassword();
    const passwordHash = await hash(tempPassword);
    await prisma.user.update({
      where: { id },
      data: { passwordHash, mustChangePassword: true, failedLoginCount: 0, lockedUntil: null },
    });

    await writeAudit({
      user: actor,
      entity: 'User',
      entityId: id,
      action: 'USER_PASSWORD_RESET',
      old: { mustChangePassword: before.mustChangePassword },
      new: { mustChangePassword: true },
    });

    return ok({ tempPassword });
  });
}
