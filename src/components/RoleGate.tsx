'use client';

import { useSession } from 'next-auth/react';
import type { Role } from '@prisma/client';

type Props = {
  roles: Role[];
  children: React.ReactNode;
  /** Rendered while the session is loading or the role is not allowed. */
  fallback?: React.ReactNode;
};

/**
 * UI-only role gate: hides content the signed-in user's role does not allow.
 * Server-side enforcement always uses requireRole()/requirePageRole() — this
 * component is decoration, never the security boundary.
 * Requires a SessionProvider ancestor (AppShell renders one).
 */
export default function RoleGate({ roles, children, fallback = null }: Props) {
  const { data, status } = useSession();
  const role = data?.user?.role;

  if (status === 'loading') return <>{fallback}</>;
  if (!role || !roles.includes(role)) return <>{fallback}</>;
  return <>{children}</>;
}
