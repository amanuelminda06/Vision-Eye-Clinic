import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import SettingsForm from '@/modules/settings/components/SettingsForm';
import { getSettingsMap, settingsFromMap } from '@/modules/settings';

export default async function SettingsPage() {
  await requirePageRole(['OWNER']);
  const t = await getTranslations('settings');
  const map = await getSettingsMap();
  const initial = settingsFromMap(map);

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
      <SettingsForm initial={initial} />
    </div>
  );
}
