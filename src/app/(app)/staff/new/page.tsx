import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { StaffForm } from '@/modules/staff';

export default async function NewStaffPage() {
  await requirePageRole(['OWNER']);
  const t = await getTranslations('staff');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('newStaff')}</h1>
      <StaffForm mode="create" />
    </div>
  );
}
