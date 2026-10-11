import { getSettingsMap } from '@/modules/settings';
import { listActiveServices } from '@/modules/services/queries';
import { listAudiences, listDrafts, listLibraryItems, listTemplates } from './queries';
import { canOverrideGroupFilter } from './roles';
import type { Role } from '@prisma/client';

/** Everything the message wizard needs, loaded once per page render. */
export type WizardData = {
  templates: Awaited<ReturnType<typeof listTemplates>>;
  services: { id: string; name: string }[];
  library: Awaited<ReturnType<typeof listLibraryItems>>;
  drafts: Awaited<ReturnType<typeof listDrafts>>;
  audiences: Awaited<ReturnType<typeof listAudiences>>;
  allowGroupOverride: boolean;
};

export async function loadWizardData(userId: string, role: Role): Promise<WizardData> {
  const [templates, serviceRows, library, drafts, audiences, settings] = await Promise.all([
    listTemplates(),
    listActiveServices(),
    listLibraryItems(),
    listDrafts(userId),
    listAudiences(),
    getSettingsMap(),
  ]);
  return {
    templates,
    services: serviceRows.map((row) => ({ id: row.id, name: row.name })),
    library,
    drafts,
    audiences,
    allowGroupOverride: canOverrideGroupFilter(role) && settings.group_sms_allow_for_filtered === true,
  };
}
