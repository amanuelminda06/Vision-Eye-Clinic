import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { getUser, listStaffOptions, UserForm } from '@/modules/users';

export default async function EditUserPage({ params }: { params: Promise<{ id: string }> }) {
  await requirePageRole(['OWNER']);
  const { id } = await params;
  const [user, staffOptions] = await Promise.all([getUser(id), listStaffOptions()]);
  if (!user) notFound();
  const t = await getTranslations('users');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('editUser')}</h1>
      <UserForm mode="edit" user={user} staffOptions={staffOptions} />
    </div>
  );
}
