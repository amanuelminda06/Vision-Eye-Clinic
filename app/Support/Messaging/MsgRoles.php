<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Support\Roles;

/**
 * Messaging role sets and predicates, ported from
 * src/modules/messaging/roles.ts.
 */
final class MsgRoles
{
    /** @var list<string> Viewing and sending prepared messages. */
    public const MESSAGE_ROLES = [Roles::OWNER, Roles::RECEPTION];

    /** @var list<string> Template editing, campaign creation, reports, jobs. */
    public const MESSAGE_OWNER_ROLES = [Roles::OWNER];

    public static function canEditTemplates(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /** Curating the Message Library is owner-only; everyone may reuse. */
    public static function canManageLibrary(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /** Saved audiences are owner-managed. */
    public static function canManageAudiences(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    /** The group-delivery override setting is owner-only. */
    public static function canOverrideGroupFilter(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    public static function canSendMessages(?string $role): bool
    {
        return $role === Roles::OWNER || $role === Roles::RECEPTION;
    }

    /**
     * Campaigns are OWNER-only unless the `reception_can_send_campaigns`
     * setting is true, in which case reception may also create them.
     */
    public static function canSendCampaigns(?string $role, bool $receptionAllowed): bool
    {
        if ($role === Roles::OWNER) {
            return true;
        }

        return $role === Roles::RECEPTION && $receptionAllowed;
    }
}
