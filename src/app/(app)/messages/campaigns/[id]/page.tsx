import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { requirePageRole } from '@/lib/require-role';
import { MESSAGE_ROLES, canSendCampaigns } from '@/modules/messaging';
import { CAMPAIGN_STATUS_LABEL_KEYS } from '@/modules/messaging/constants';
import { getCampaign, listCampaignQueue } from '@/modules/messaging/queries';
import CampaignQueue from '@/modules/messaging/components/CampaignQueue';

type Params = { params: Promise<{ id: string }> };

export default async function CampaignDetailPage({ params }: Params) {
  const user = await requirePageRole(MESSAGE_ROLES);
  const t = await getTranslations('messages');
  const { id } = await params;

  const campaign = await getCampaign(id);
  if (!campaign) notFound();

  const items = await listCampaignQueue(id);
  const canManage = canSendCampaigns(user.role, true);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{campaign.title}</h1>
          <p className="mt-1 text-sm text-slate-600">
            {t(
              CAMPAIGN_STATUS_LABEL_KEYS[
                campaign.status as keyof typeof CAMPAIGN_STATUS_LABEL_KEYS
              ] as never,
            )}
          </p>
        </div>
        <Link href="/messages/campaigns" className="btn-secondary">
          {t('backToCampaigns')}
        </Link>
      </div>

      <CampaignQueue
        campaignId={campaign.id}
        items={items}
        canManage={canManage}
        progress={{
          sent: campaign.sent,
          pending: campaign.pending,
          skipped: campaign.skipped,
          total: campaign.total,
        }}
      />
    </div>
  );
}
