<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sidebar navigation modules, ported from src/components/nav.ts (NAV_MODULES).
 *
 * Structure mirrors the legacy table exactly: module key, optional label key,
 * and items with label key / href / allowed roles. The layout drops items the
 * current role cannot see and removes modules that end up empty (rule 14).
 */
final class Nav
{
    public const ROLE_ALL = [Roles::OWNER, Roles::RECEPTION, Roles::STAFF];

    public const ROLE_OR = [Roles::OWNER, Roles::RECEPTION];

    public const ROLE_OWNER = [Roles::OWNER];

    /**
     * @return list<array{key: string, labelKey: ?string, items: list<array{labelKey: string, href: string, roles: list<string>, badge: ?string}>}>
     */
    public static function modules(): array
    {
        return [
            [
                'key' => 'dashboard',
                'labelKey' => null,
                'items' => [
                    ['labelKey' => 'nav.dashboard', 'href' => '/dashboard', 'roles' => self::ROLE_ALL, 'badge' => null],
                    ['labelKey' => 'nav.dailySummary', 'href' => '/dashboard/daily', 'roles' => self::ROLE_OWNER, 'badge' => null],
                ],
            ],
            [
                'key' => 'pms',
                'labelKey' => 'nav.pms',
                'items' => [
                    ['labelKey' => 'nav.patients', 'href' => '/patients', 'roles' => self::ROLE_OR, 'badge' => null],
                    ['labelKey' => 'nav.sales', 'href' => '/sales', 'roles' => self::ROLE_OR, 'badge' => null],
                    ['labelKey' => 'nav.revenue', 'href' => '/revenue', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.appointments', 'href' => '/appointments', 'roles' => self::ROLE_OR, 'badge' => null],
                    ['labelKey' => 'nav.followUps', 'href' => '/follow-ups', 'roles' => self::ROLE_OR, 'badge' => null],
                    ['labelKey' => 'nav.requests', 'href' => '/requests', 'roles' => self::ROLE_OR, 'badge' => 'requests'],
                ],
            ],
            [
                'key' => 'hrm',
                'labelKey' => 'nav.hrm',
                'items' => [
                    ['labelKey' => 'nav.staff', 'href' => '/staff', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.users', 'href' => '/users', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.leave', 'href' => '/leave', 'roles' => self::ROLE_ALL, 'badge' => null],
                    ['labelKey' => 'nav.leaveInbox', 'href' => '/leave/inbox', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.leaveCalendar', 'href' => '/leave/calendar', 'roles' => self::ROLE_OR, 'badge' => null],
                    ['labelKey' => 'nav.schedule', 'href' => '/schedule', 'roles' => self::ROLE_ALL, 'badge' => null],
                ],
            ],
            [
                'key' => 'mms',
                'labelKey' => 'nav.mms',
                'items' => [
                    ['labelKey' => 'nav.messages', 'href' => '/messages', 'roles' => self::ROLE_OR, 'badge' => 'messages'],
                ],
            ],
            [
                'key' => 'cms',
                'labelKey' => 'nav.cms',
                'items' => [
                    ['labelKey' => 'nav.services', 'href' => '/dashboard/services', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.websiteContent', 'href' => '/dashboard/content', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.feedback', 'href' => '/dashboard/feedback', 'roles' => self::ROLE_OWNER, 'badge' => null],
                    ['labelKey' => 'nav.settings', 'href' => '/settings', 'roles' => self::ROLE_OWNER, 'badge' => null],
                ],
            ],
            [
                'key' => 'audit',
                'labelKey' => null,
                'items' => [
                    ['labelKey' => 'nav.audit', 'href' => '/audit', 'roles' => self::ROLE_OWNER, 'badge' => null],
                ],
            ],
        ];
    }

    /**
     * Modules/items visible to a role (modules with zero visible items dropped).
     *
     * @return list<array{key: string, labelKey: ?string, items: list<array{labelKey: string, href: string, roles: list<string>, badge: ?string}>}>
     */
    public static function visibleFor(string $role): array
    {
        $out = [];

        foreach (self::modules() as $module) {
            $items = array_values(array_filter(
                $module['items'],
                fn (array $item) => in_array($role, $item['roles'], true),
            ));

            if ($items !== []) {
                $out[] = ['key' => $module['key'], 'labelKey' => $module['labelKey'], 'items' => $items];
            }
        }

        return $out;
    }

    /** True when the href (or any of its children) is the current page. */
    public static function isActive(string $href): bool
    {
        $current = '/'.trim(request()->path(), '/');

        if ($current === $href) {
            return true;
        }

        // Parent sections highlight for child pages (/patients → /patients/123).
        return $href !== '/dashboard' && str_starts_with($current, $href.'/');
    }
}
