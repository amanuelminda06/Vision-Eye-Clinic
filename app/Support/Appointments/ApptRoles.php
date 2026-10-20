<?php

declare(strict_types=1);

namespace App\Support\Appointments;

use App\Support\Roles;

/**
 * Appointment role sets, ported from src/modules/appointments/roles.ts.
 */
final class ApptRoles
{
    /** @var list<string> Viewing/managing appointments + follow-ups. */
    public const APPT_ROLES = [Roles::OWNER, Roles::RECEPTION];

    /** @var list<string> Follow-up administration. */
    public const FOLLOW_UP_ROLES = [Roles::OWNER, Roles::RECEPTION];

    /** @var list<string> Terminal-state corrections. */
    public const APPT_OWNER_ROLES = [Roles::OWNER];

    public static function canOverrideTerminalTransition(?string $role): bool
    {
        return $role === Roles::OWNER;
    }

    public static function canManageAppointments(?string $role): bool
    {
        return $role === Roles::OWNER || $role === Roles::RECEPTION;
    }

    public static function canManageFollowUps(?string $role): bool
    {
        return $role === Roles::OWNER || $role === Roles::RECEPTION;
    }
}
