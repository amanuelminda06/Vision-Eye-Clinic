<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Support\Roles;

/**
 * HR role sets and predicates, ported from src/modules/hr/roles.ts.
 */
final class HrRoles
{
    /** @var list<string> Seeing the schedule (scoped to self for STAFF) and one's own leave. */
    public const HR_VIEW_ROLES = [Roles::OWNER, Roles::RECEPTION, Roles::STAFF];

    /** @var list<string> The team leave calendar. */
    public const HR_TEAM_LEAVE_ROLES = [Roles::OWNER, Roles::RECEPTION];

    /** @var list<string> Creating/editing shifts and templates. */
    public const HR_MANAGE_ROLES = [Roles::OWNER];

    /** @var list<string> Requesting leave for oneself. */
    public const HR_LEAVE_ROLES = [Roles::OWNER, Roles::RECEPTION, Roles::STAFF];

    /** @var list<string> Deciding leave and cancelling an approved request. */
    public const HR_OWNER_ROLES = [Roles::OWNER];

    /** Only the OWNER manages the schedule. */
    public static function canManageSchedule(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /** OWNER + RECEPTION see the whole team schedule; STAFF see only themselves. */
    public static function canViewTeamSchedule(?string $role): bool
    {
        return $role === Roles::OWNER || $role === Roles::RECEPTION;
    }

    /** OWNER + RECEPTION see the team leave calendar; STAFF see only their own. */
    public static function canViewTeamLeave(?string $role): bool
    {
        return $role === Roles::OWNER || $role === Roles::RECEPTION;
    }

    /** Only the OWNER approves or rejects a leave request. */
    public static function canDecideLeave(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /** Only the OWNER enters leave on behalf of another staff member. */
    public static function canRequestForOthers(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /**
     * Segregation of duties: nobody may decide their own leave. The check
     * compares the actor's linked staffId with the request's staffId — a user
     * account with no staff link is never "self".
     */
    public static function isSelfApproval(?string $actorStaffId, string $requestStaffId): bool
    {
        return $actorStaffId !== null && $actorStaffId !== '' && $actorStaffId === $requestStaffId;
    }

    /**
     * Cancellation: the requester may cancel their own still-pending request;
     * once approved (or for anyone else's request) only the OWNER may cancel.
     *
     * @param  array{role: ?string, isSelf: bool, status: string}  $options
     */
    public static function canCancelLeave(array $options): bool
    {
        $status = $options['status'];

        if ($status === 'CANCELLED' || $status === 'REJECTED') {
            return false;
        }

        if ($options['role'] === Roles::OWNER) {
            return true;
        }

        return $options['isSelf'] && $status === 'REQUESTED';
    }
}
