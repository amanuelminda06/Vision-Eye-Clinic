import { hash, verify } from '@node-rs/argon2';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ValidationError } from '@/lib/errors';
import { passwordError } from '@/lib/passwords';

/**
 * Changes a user's own password after verifying the current one.
 * - enforces min length / common-password / personal-info rules
 * - clears mustChangePassword (first-login force)
 * - audits PASSWORD_CHANGED (passwordHash values are redacted by writeAudit)
 * Throws ValidationError with field keys on failure.
 */
export async function changeUserPassword(
  user: { id: string; email: string; name: string },
  currentPassword: string,
  newPassword: string,
): Promise<void> {
  const record = await prisma.user.findUnique({ where: { id: user.id } });
  if (!record) throw new ValidationError({ currentPassword: 'currentPasswordWrong' });

  const currentOk = await verify(record.passwordHash, currentPassword);
  if (!currentOk) throw new ValidationError({ currentPassword: 'currentPasswordWrong' });

  const strength = passwordError(newPassword, { email: record.email, name: record.name });
  if (strength) throw new ValidationError({ newPassword: strength });

  const passwordHash = await hash(newPassword);
  await prisma.user.update({
    where: { id: record.id },
    data: { passwordHash, mustChangePassword: false },
  });

  await writeAudit({
    user: { id: record.id, name: record.name },
    entity: 'User',
    entityId: record.id,
    action: 'PASSWORD_CHANGED',
    old: { passwordHash: record.passwordHash },
    new: { passwordHash },
  });
}
