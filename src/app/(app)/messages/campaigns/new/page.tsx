import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { getSettingsMap } from '@/modules/settings';
import { MESSAGE_ROLES, canSendCampaigns } from '@/modules/messaging';
import { loadWizardData } from '@/modules/messaging/wizard-data';
import MessageWizard from '@/modules/messaging/components/MessageWizard';

export default async function NewCampaignPage() {
  const user = await requirePageRole(MESSAGE_ROLES);
  const t = await getTranslations('messages');
  const [settings, data] = await Promise.all([getSettingsMap(), loadWizardData(user.id, user.role)]);
  const canSend = canSendCampaigns(user.role, settings.reception_can_send_campaigns === true);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('newCampaign')}</h1>
        <Link href="/messages/campaigns" className="btn-secondary">
          {t('backToCampaigns')}
        </Link>
      </div>
      <MessageWizard
        {...data}
        canSend={canSend}
        canManageLibrary={user.role === 'OWNER'}
        canManageAudiences={user.role === 'OWNER'}
        presetDelivery="INDIVIDUAL"
        createLabelKey="createCampaign"
      />
    </div>
  );
}
