'use server';

import { requireAuth } from '@/lib/require-role';
import { fail, ok, type ActionResult } from '@/lib/action';
import { validate, ValidationError } from '@/lib/errors';
import { changePasswordSchema } from './schemas';
import { changeUserPassword } from './services/change-password';

export async function changePasswordAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  try {
    const user = await requireAuth();
    const values = validate(changePasswordSchema, {
      currentPassword: String(formData.get('currentPassword') ?? ''),
      newPassword: String(formData.get('newPassword') ?? ''),
      confirmPassword: String(formData.get('confirmPassword') ?? ''),
    });
    await changeUserPassword(user, values.currentPassword, values.newPassword);
    return ok();
  } catch (err) {
    if (err instanceof ValidationError) return fail('validationError', err.fields);
    throw err;
  }
}
