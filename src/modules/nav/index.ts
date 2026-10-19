import type { Role } from '@prisma/client';

/**
 * A single destination in the sidebar. Mirrors the previous `NavItem` shape so
 * callers (AppShell, layout) can keep passing badges through unchanged.
 */
export type NavItem = {
  href: string;
  labelKey: string;
  roles: Role[];
  /** Optional count shown as a pill, e.g. new appointment requests. */
  badge?: number;
};

/**
 * A collapsible module group. Each module owns a set of routes; clicking the
 * header expands/collapses its children. Modules with no visible items for the
 * current role are hidden entirely.
 */
export type NavModule = {
  key: string;
  /** Short acronym shown before the full name, e.g. `PMS`. */
  acronym?: string;
  /** Full module name, e.g. `Patients Management System`. */
  fullName?: string;
  /** Translation key for the module heading (falls back to `labelKey`). */
  labelKey: string;
  items: NavItem[];
};

export const NAV_MODULES: NavModule[] = [
  {
    key: 'dashboard',
    labelKey: 'dashboard',
    items: [
      { href: '/dashboard', labelKey: 'dashboard', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
      { href: '/dashboard/daily', labelKey: 'dailySummary', roles: ['OWNER'] },
    ],
  },
  {
    key: 'pms',
    acronym: 'PMS',
    fullName: 'Patients Management System',
    labelKey: 'pms',
    items: [
      { href: '/patients', labelKey: 'patients', roles: ['OWNER', 'RECEPTION'] },
      { href: '/sales', labelKey: 'sales', roles: ['OWNER', 'RECEPTION'] },
      { href: '/revenue', labelKey: 'revenue', roles: ['OWNER'] },
      { href: '/appointments', labelKey: 'appointments', roles: ['OWNER', 'RECEPTION'] },
      { href: '/follow-ups', labelKey: 'followUps', roles: ['OWNER', 'RECEPTION'] },
    ],
  },
  {
    key: 'hrm',
    acronym: 'HRM',
    fullName: 'Human Resource Management System',
    labelKey: 'hrm',
    items: [
      { href: '/staff', labelKey: 'staff', roles: ['OWNER'] },
      { href: '/users', labelKey: 'users', roles: ['OWNER'] },
      { href: '/leave', labelKey: 'leave', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
      { href: '/leave/inbox', labelKey: 'leaveInbox', roles: ['OWNER'] },
      { href: '/leave/calendar', labelKey: 'leaveCalendar', roles: ['OWNER', 'RECEPTION'] },
      { href: '/schedule', labelKey: 'schedule', roles: ['OWNER', 'RECEPTION', 'STAFF'] },
    ],
  },
  {
    key: 'mms',
    acronym: 'MMS',
    fullName: 'Messages Management System',
    labelKey: 'mms',
    items: [{ href: '/messages', labelKey: 'messages', roles: ['OWNER', 'RECEPTION'] }],
  },
  {
    key: 'cms',
    acronym: 'CMS',
    fullName: 'Clinic Management System',
    labelKey: 'cms',
    items: [
      { href: '/dashboard/services', labelKey: 'services', roles: ['OWNER'] },
      { href: '/dashboard/content', labelKey: 'websiteContent', roles: ['OWNER'] },
      { href: '/dashboard/feedback', labelKey: 'feedback', roles: ['OWNER'] },
      { href: '/settings', labelKey: 'settings', roles: ['OWNER'] },
    ],
  },
  {
    key: 'audit',
    labelKey: 'audit',
    items: [{ href: '/audit', labelKey: 'audit', roles: ['OWNER'] }],
  },
];

/** Flat list of every nav item, preserving module order. */
export function allNavItems(): NavItem[] {
  return NAV_MODULES.flatMap((m) => m.items);
}

/** The module that owns a given pathname, if any. */
export function moduleForPathname(pathname: string): NavModule | undefined {
  return NAV_MODULES.find((module) =>
    module.items.some((item) => pathname === item.href || pathname.startsWith(item.href + '/')),
  );
}
