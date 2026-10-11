'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { SessionProvider, signOut } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import type { Role } from '@prisma/client';
import LocaleSwitcher from '@/components/LocaleSwitcher';
import GlobalSearch from '@/components/GlobalSearch';

type NavItem = {
  href: string;
  labelKey: string;
  roles: Role[];
  /** Optional count shown as a pill, e.g. new appointment requests. */
  badge?: number;
};

/** Role-based menu. Items for modules not built yet are simply not listed. */
const NAV_ITEMS: NavItem[] = [
  { href: '/dashboard', labelKey: 'dashboard', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
  { href: '/patients', labelKey: 'patients', roles: ['OWNER', 'RECEPTION'] },
  { href: '/sales', labelKey: 'sales', roles: ['OWNER', 'RECEPTION'] },
  { href: '/schedule', labelKey: 'schedule', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
  { href: '/leave', labelKey: 'leave', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
  { href: '/leave/inbox', labelKey: 'leaveInbox', roles: ['OWNER'] },
  { href: '/leave/calendar', labelKey: 'leaveCalendar', roles: ['OWNER', 'RECEPTION'] },
  { href: '/appointments', labelKey: 'appointments', roles: ['OWNER', 'RECEPTION'] },
  { href: '/requests', labelKey: 'requests', roles: ['OWNER', 'RECEPTION'] },
  { href: '/follow-ups', labelKey: 'followUps', roles: ['OWNER', 'RECEPTION'] },
  { href: '/messages', labelKey: 'messages', roles: ['OWNER', 'RECEPTION'] },
  { href: '/revenue', labelKey: 'revenue', roles: ['OWNER'] },
  { href: '/dashboard/services', labelKey: 'services', roles: ['OWNER'] },
  { href: '/dashboard/content', labelKey: 'websiteContent', roles: ['OWNER'] },
  { href: '/users', labelKey: 'users', roles: ['OWNER'] },
  { href: '/staff', labelKey: 'staff', roles: ['OWNER'] },
  { href: '/settings', labelKey: 'settings', roles: ['OWNER'] },
  { href: '/audit', labelKey: 'audit', roles: ['OWNER'] },
];

type Props = {
  user: { name: string; email: string; role: Role; mustChangePassword: boolean };
  loginUrl: string;
  /** Unhandled website-appointment requests → shown as a badge on /requests. */
  requestCount?: number;
  /** Messages due now or overdue → shown as a badge on /messages. */
  messageCount?: number;
  children: React.ReactNode;
};

export default function AppShell({
  user,
  loginUrl,
  requestCount = 0,
  messageCount = 0,
  children,
}: Props) {
  const pathname = usePathname();
  const router = useRouter();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  const forced = user.mustChangePassword && pathname !== '/change-password';

  // Forced password change: bounce every page except /change-password (rule 3).
  useEffect(() => {
    if (forced) router.replace('/change-password');
  }, [forced, router]);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setDrawerOpen(false);
        setMenuOpen(false);
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const items = NAV_ITEMS.filter((item) => item.roles.includes(user.role)).map((item) =>
    item.href === '/requests'
      ? { ...item, badge: requestCount }
      : item.href === '/messages'
        ? { ...item, badge: messageCount }
        : item,
  );

  return (
    <SessionProvider>
      <Shell
        user={user}
        loginUrl={loginUrl}
        items={items}
        forced={forced}
        drawerOpen={drawerOpen}
        setDrawerOpen={setDrawerOpen}
        menuOpen={menuOpen}
        setMenuOpen={setMenuOpen}
        pathname={pathname}
      >
        {children}
      </Shell>
    </SessionProvider>
  );
}

type ShellProps = Props & {
  items: NavItem[];
  forced: boolean;
  drawerOpen: boolean;
  setDrawerOpen: (fn: (open: boolean) => boolean) => void;
  menuOpen: boolean;
  setMenuOpen: (fn: (open: boolean) => boolean) => void;
  pathname: string;
};

function Shell({
  user,
  loginUrl,
  items,
  forced,
  drawerOpen,
  setDrawerOpen,
  menuOpen,
  setMenuOpen,
  pathname,
  children,
}: ShellProps) {
  const t = useTranslations('app');
  const tn = useTranslations('nav');
  const tc = useTranslations('common');

  const navLinks = (className: string) =>
    items.map((item) => (
      <Link
        key={item.href}
        href={item.href}
        onClick={() => {
          setDrawerOpen(() => false);
          setMenuOpen(() => false);
        }}
        aria-current={pathname === item.href ? 'page' : undefined}
        className={
          className +
          (pathname === item.href
            ? ' bg-orange-100 text-orange-900'
            : ' text-slate-600 hover:bg-orange-50 hover:text-orange-900')
        }
      >
        {tn(item.labelKey as 'dashboard')}
        {item.badge != null && item.badge > 0 ? (
          <span className="ml-1.5 inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">
            {item.badge}
          </span>
        ) : null}
      </Link>
    ));

  return (
    <div className="min-h-screen">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white">
        <div className="flex h-14 items-center gap-3 px-4">
          <button
            type="button"
            className="btn-secondary md:hidden"
            aria-expanded={drawerOpen}
            aria-label={tn('menu')}
            onClick={() => setDrawerOpen((open) => !open)}
          >
            ☰
          </button>

          <Link href="/dashboard" className="text-sm font-semibold text-slate-900">
            {t('shortName')}
          </Link>

          {user.role !== 'STAFF' ? (
            <div className="ml-2 hidden sm:block">
              <GlobalSearch />
            </div>
          ) : null}

          <div className="ml-auto flex items-center gap-2 sm:gap-3">
            <LocaleSwitcher />

            <div className="relative">
              <button
                type="button"
                aria-expanded={menuOpen}
                onClick={() => setMenuOpen((open) => !open)}
                className="flex items-center gap-2 rounded-lg border border-slate-200 px-2 py-1.5 text-left hover:bg-slate-50"
              >
                <span className="hidden text-xs leading-tight sm:block">
                  <span className="block font-medium text-slate-800">{user.name}</span>
                  <span className="block text-slate-400">
                    {tc(
                      user.role === 'OWNER'
                        ? 'roleOwner'
                        : user.role === 'RECEPTION'
                          ? 'roleReception'
                          : 'roleStaff',
                    )}
                  </span>
                </span>
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
                  {(user.name || '?').slice(0, 1).toUpperCase()}
                </span>
              </button>

              {menuOpen ? (
                <div className="absolute right-0 top-full mt-1 w-52 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                  <div className="border-b border-slate-100 px-3 py-2">
                    <div className="truncate text-xs font-medium text-slate-800">{user.email}</div>
                  </div>
                  <Link
                    href="/change-password"
                    onClick={() => setMenuOpen(() => false)}
                    className="block px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    {tc('changePassword')}
                  </Link>
                  <button
                    type="button"
                    className="block w-full px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50"
                    onClick={() => void signOut({ redirectTo: loginUrl })}
                  >
                    {tc('signOut')}
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </header>

      <div className="mx-auto flex w-full max-w-7xl">
        <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-56 shrink-0 flex-col gap-1 overflow-y-auto border-r border-slate-200 p-3 md:flex">
          {navLinks('block rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600')}
        </aside>

        <main className="min-w-0 flex-1 px-4 py-6 md:px-6">{forced ? null : children}</main>
      </div>

      {drawerOpen ? (
        <div className="fixed inset-0 z-40 md:hidden">
          <button
            type="button"
            aria-label={tc('close')}
            className="absolute inset-0 bg-slate-900/40"
            onClick={() => setDrawerOpen(() => false)}
          />
          <nav className="absolute left-0 top-0 h-full w-64 bg-white p-3 shadow-xl">
            <div className="mb-3 flex items-center justify-between px-2">
              <span className="text-sm font-semibold">{t('shortName')}</span>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setDrawerOpen(() => false)}
              >
                {tc('close')}
              </button>
            </div>
            <div className="flex flex-col gap-1">
              {navLinks('block rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600')}
            </div>
            <button
              type="button"
              className="mt-4 block w-full rounded-md px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50"
              onClick={() => void signOut({ redirectTo: loginUrl })}
            >
              {tc('signOut')}
            </button>
          </nav>
        </div>
      ) : null}
    </div>
  );
}
