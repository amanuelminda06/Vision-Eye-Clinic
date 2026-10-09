import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { listStaffOptions, UserForm } from '@/modules/users';

export default async function NewUserPage() {
  await requirePageRole(['OWNER']);
  const t = await getTranslations('users');
  const staffOptions = await listStaffOptions();

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('newUser')}</h1>
      <UserForm mode="create" staffOptions={staffOptions} />
    </div>
  );
}
