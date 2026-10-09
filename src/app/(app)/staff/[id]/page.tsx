import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { getStaff, StaffForm } from '@/modules/staff';

export default async function EditStaffPage({ params }: { params: Promise<{ id: string }> }) {
  await requirePageRole(['OWNER']);
  const { id } = await params;
  const staff = await getStaff(id);
  if (!staff) notFound();
  const t = await getTranslations('staff');

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('editStaff')}</h1>
      <StaffForm mode="edit" staff={staff} />
    </div>
  );
}
