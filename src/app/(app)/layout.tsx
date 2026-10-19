import { redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { auth, loginPath } from '@/auth';
import AppShell from '@/components/AppShell';
import { countDueTasks } from '@/modules/messaging/queries';
import MessagesTicker from '@/modules/messaging/components/MessagesTicker';

/**
 * Real authentication gate for every ERP page (rule 2). The proxy only does an
 * optimistic cookie sniff; `auth()` verifies the JWT signature here.
 */
export default async function ProtectedLayout({ children }: { children: React.ReactNode }) {
  const session = await auth();
  const user = session?.user;
  if (!user?.id) redirect(loginPath);

  const t = await getTranslations('app');
  const canSeeMessages = user.role === 'OWNER' || user.role === 'RECEPTION';
  const messageCount = canSeeMessages ? await countDueTasks().catch(() => 0) : 0;

  return (
    <AppShell
      user={{
        name: user.name ?? user.email ?? '',
        email: user.email ?? '',
        role: user.role,
        mustChangePassword: user.mustChangePassword,
      }}
      loginUrl={loginPath}
      messageCount={messageCount}
    >
      {canSeeMessages ? <MessagesTicker baseTitle={t('name')} /> : null}
      {children}
    </AppShell>
  );
}
