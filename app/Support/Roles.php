<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Role constants and permission matrix. Mirrors src/lib/auth/roles.ts —
 * server-side authorization is authoritative (the client copy is display-only).
 */
final class Roles
{
    public const OWNER = 'OWNER';

    public const RECEPTION = 'RECEPTION';

    public const STAFF = 'STAFF';

    /** @var list<string> Legacy ALL_ROLES (src/lib/roles.ts). */
    public const ALL_ROLES = [self::OWNER, self::RECEPTION, self::STAFF];

    /** Alias kept for callers written against the earlier port. */
    public const ALL = self::ALL_ROLES;

    /** Legacy isRole (src/lib/roles.ts): guards unknown role values. */
    public static function isRole(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL_ROLES, true);
    }

    /** Legacy hasRole: null-safe membership check against an allowed list. */
    public static function hasRole(?string $role, array $allowed): bool
    {
        return $role !== null && $role !== '' && in_array($role, $allowed, true);
    }

    /**
     * Route prefix → roles allowed. Anything not listed is denied to everyone
     * except where a per-route check exists.
     *
     * @var array<string, list<string>>
     */
    private const ROUTE_ACCESS = [
        // Owner only
        'settings' => [self::OWNER],
        'staff' => [self::OWNER],
        'services' => [self::OWNER],
        'reports' => [self::OWNER],
        'audit' => [self::OWNER],
        'templates' => [self::OWNER],
        'library' => [self::OWNER],
        'roles' => [self::OWNER],

        // Owner + reception
        'patients' => [self::OWNER, self::RECEPTION],
        'appointments' => [self::OWNER, self::RECEPTION],
        'requests' => [self::OWNER, self::RECEPTION],
        'sales' => [self::OWNER, self::RECEPTION],
        'pos' => [self::OWNER, self::RECEPTION],
        'cash' => [self::OWNER, self::RECEPTION],
        'campaigns' => [self::OWNER, self::RECEPTION],
        'messages' => [self::OWNER, self::RECEPTION],
        'tasks' => [self::OWNER, self::RECEPTION],
        'drafts' => [self::OWNER, self::RECEPTION],
        'feedback' => [self::OWNER, self::RECEPTION],
        'followups' => [self::OWNER, self::RECEPTION],

        // Owner + staff (own things)
        'shifts' => self::ALL,
        'leave' => self::ALL,
    ];

    /** Sections a role may view on the dashboard / nav. */
    private const SECTION_ACCESS = [
        'frontdesk' => [self::OWNER, self::RECEPTION],
        'clinical' => [self::OWNER, self::RECEPTION],
        'sales' => [self::OWNER, self::RECEPTION],
        'messaging' => [self::OWNER, self::RECEPTION],
        'staff' => self::ALL,
        'reports' => [self::OWNER],
        'settings' => [self::OWNER],
    ];

    public static function canAccessRoute(string $prefix, string $role): bool
    {
        $allowed = self::ROUTE_ACCESS[$prefix] ?? null;

        if ($allowed === null) {
            return false;
        }

        return in_array($role, $allowed, true);
    }

    public static function canSeeSection(string $section, string $role): bool
    {
        $allowed = self::SECTION_ACCESS[$section] ?? null;

        return $allowed !== null && in_array($role, $allowed, true);
    }

    public static function isOwner(string $role): bool
    {
        return $role === self::OWNER;
    }

    public static function canManage(string $role): bool
    {
        return in_array($role, [self::OWNER, self::RECEPTION], true);
    }

    /** Owner + reception can do patient/appointment/sales work. */
    public static function canDoReception(string $role): bool
    {
        return self::canManage($role);
    }

    // -----------------------------------------------------------------------
    // HR predicates (src/modules/hr/roles.ts)
    // -----------------------------------------------------------------------

    /** @var list<string> HR_MANAGE_ROLES — creating/editing shifts and templates. */
    public const HR_MANAGE_ROLES = [self::OWNER];

    /** @var list<string> HR_LEAVE_ROLES — requesting leave. */
    public const HR_LEAVE_ROLES = [self::OWNER, self::RECEPTION, self::STAFF];

    /** @var list<string> HR_OWNER_ROLES — deciding leave. */
    public const HR_OWNER_ROLES = [self::OWNER];

    /** @var list<string> HR_TEAM_LEAVE_ROLES — team leave calendar. */
    public const HR_TEAM_LEAVE_ROLES = [self::OWNER, self::RECEPTION];

    /** @var list<string> HR_VIEW_ROLES — viewing the schedule / own leave. */
    public const HR_VIEW_ROLES = [self::OWNER, self::RECEPTION, self::STAFF];

    /** Only the OWNER manages the schedule. */
    public static function canManageSchedule(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /** OWNER + RECEPTION see the whole team schedule; STAFF only themselves. */
    public static function canViewTeamSchedule(?string $role): bool
    {
        return $role === self::OWNER || $role === self::RECEPTION;
    }

    /** OWNER + RECEPTION see the team leave calendar; STAFF only their own. */
    public static function canViewTeamLeave(?string $role): bool
    {
        return $role === self::OWNER || $role === self::RECEPTION;
    }

    /** Only the OWNER approves or rejects a leave request. */
    public static function canDecideLeave(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /** Only the OWNER enters leave on behalf of another staff member. */
    public static function canRequestForOthers(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /**
     * Segregation of duties: nobody may decide their own leave.
     */
    public static function isSelfApproval(?string $actorStaffId, string $requestStaffId): bool
    {
        return $actorStaffId !== null && $actorStaffId !== '' && $actorStaffId === $requestStaffId;
    }

    /**
     * Cancellation: the requester may cancel their own still-pending request;
     * once approved (or for anyone else's request) only the OWNER may cancel.
     */
    public static function canCancelLeave(?string $role, bool $isSelf, string $status): bool
    {
        if ($status === 'CANCELLED' || $status === 'REJECTED') {
            return false;
        }

        if ($role === self::OWNER) {
            return true;
        }

        return $isSelf && $status === 'REQUESTED';
    }

    // -----------------------------------------------------------------------
    // Sales predicates (src/modules/sales/roles.ts + src/modules/services/roles.ts)
    // -----------------------------------------------------------------------

    /** @var list<string> SALES_ROLES — creating and confirming sales. */
    public const SALES_ROLES = [self::OWNER, self::RECEPTION];

    /** @var list<string> SALES_OWNER_ROLES — revenue, void approval, direct voids. */
    public const SALES_OWNER_ROLES = [self::OWNER];

    /** @var list<string> SERVICES_ROLES — service management (prices) is OWNER-only. */
    public const SERVICES_ROLES = [self::OWNER];

    /** Nothing financial ("Revenue"/"Sales" totals) is visible to STAFF. */
    public static function canViewRevenue(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /** Only the OWNER approves or rejects a pending void request. */
    public static function canApproveVoid(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /** Only the OWNER voids a confirmed sale directly. */
    public static function canVoidDirectly(?string $role): bool
    {
        return $role === self::OWNER;
    }

    /** RECEPTION (and OWNER) may ask the OWNER to void a sale. */
    public static function canRequestVoid(?string $role): bool
    {
        return $role === self::OWNER || $role === self::RECEPTION;
    }

    /** OWNER sees every sale; RECEPTION only their own, today. */
    public static function canSeeAllSales(?string $role): bool
    {
        return $role === self::OWNER;
    }
}
