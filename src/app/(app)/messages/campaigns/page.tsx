import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { getSettingsMap } from '@/modules/settings';
import { MESSAGE_ROLES, canSendCampaigns } from '@/modules/messaging';
import { CAMPAIGN_STATUS_LABEL_KEYS } from '@/modules/messaging/constants';
import { listCampaigns } from '@/modules/messaging/queries';

export default async function CampaignsPage() {
  const user = await requirePageRole(MESSAGE_ROLES);
  const t = await getTranslations('messages');
  const [settings, campaigns] = await Promise.all([getSettingsMap(), listCampaigns()]);
  const canCreate = canSendCampaigns(user.role, settings.reception_can_send_campaigns === true);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{t('campaignsTitle')}</h1>
        <div className="flex gap-2">
          <Link href="/messages" className="btn-secondary">
            {t('backToOutbox')}
          </Link>
          {canCreate ? (
            <Link href="/messages/campaigns/new" className="btn-primary">
              {t('newCampaign')}
            </Link>
          ) : null}
        </div>
      </div>

      {campaigns.length === 0 ? (
        <p className="card p-5 text-sm text-slate-600">{t('noCampaigns')}</p>
      ) : (
        <ul className="card divide-y divide-slate-100">
          {campaigns.map((campaign) => (
            <li key={campaign.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
              <Link
                href={`/messages/campaigns/${campaign.id}`}
                className="font-medium text-slate-900 hover:underline"
              >
                {campaign.title}
              </Link>
              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
                {t(
                  CAMPAIGN_STATUS_LABEL_KEYS[
                    campaign.status as keyof typeof CAMPAIGN_STATUS_LABEL_KEYS
                  ] as never,
                )}
              </span>
              <span className="ml-auto text-xs text-slate-500">
                {t('progress', {
                  sent: campaign.sent,
                  total: campaign.total,
                  skipped: campaign.skipped,
                })}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
