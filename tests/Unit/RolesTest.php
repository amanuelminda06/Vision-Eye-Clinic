<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Roles;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/roles.test.ts + guards for the server-side permission
 * matrix (the single source of truth for authorization).
 */
class RolesTest extends TestCase
{
    public function test_lists_exactly_the_three_clinic_roles(): void
    {
        $this->assertSame(['OWNER', 'RECEPTION', 'STAFF'], Roles::ALL_ROLES);
    }

    public function test_is_role_guards_unknown_values(): void
    {
        $this->assertTrue(Roles::isRole('OWNER'));
        $this->assertTrue(Roles::isRole('RECEPTION'));
        $this->assertTrue(Roles::isRole('STAFF'));
        $this->assertFalse(Roles::isRole('SUPERADMIN'));
        $this->assertFalse(Roles::isRole(null));
        $this->assertFalse(Roles::isRole(''));
        $this->assertFalse(Roles::isRole('owner'));
        $this->assertFalse(Roles::isRole(1));
    }

    public function test_has_role_is_null_safe_and_only_matches_allowed_roles(): void
    {
        $this->assertTrue(Roles::hasRole('OWNER', ['OWNER']));
        $this->assertFalse(Roles::hasRole('STAFF', ['OWNER']));
        $this->assertFalse(Roles::hasRole(null, ['OWNER']));
        $this->assertFalse(Roles::hasRole('', ['OWNER']));
        $this->assertTrue(Roles::hasRole('RECEPTION', ['OWNER', 'RECEPTION']));
    }

    public function test_route_access_matrix(): void
    {
        $this->assertTrue(Roles::canAccessRoute('settings', Roles::OWNER));
        $this->assertFalse(Roles::canAccessRoute('settings', Roles::RECEPTION));
        $this->assertFalse(Roles::canAccessRoute('settings', Roles::STAFF));

        $this->assertTrue(Roles::canAccessRoute('patients', Roles::OWNER));
        $this->assertTrue(Roles::canAccessRoute('patients', Roles::RECEPTION));
        $this->assertFalse(Roles::canAccessRoute('patients', Roles::STAFF));

        $this->assertTrue(Roles::canAccessRoute('shifts', Roles::STAFF));
        $this->assertFalse(Roles::canAccessRoute('unknown-section', Roles::OWNER));
    }

    public function test_section_access_matrix(): void
    {
        $this->assertTrue(Roles::canSeeSection('reports', Roles::OWNER));
        $this->assertFalse(Roles::canSeeSection('reports', Roles::RECEPTION));
        $this->assertFalse(Roles::canSeeSection('reports', Roles::STAFF));
        $this->assertTrue(Roles::canSeeSection('staff', Roles::STAFF));
        $this->assertFalse(Roles::canSeeSection('unknown', Roles::OWNER));
    }

    public function test_role_helpers(): void
    {
        $this->assertTrue(Roles::isOwner(Roles::OWNER));
        $this->assertFalse(Roles::isOwner(Roles::RECEPTION));
        $this->assertTrue(Roles::canManage(Roles::OWNER));
        $this->assertTrue(Roles::canManage(Roles::RECEPTION));
        $this->assertFalse(Roles::canManage(Roles::STAFF));
    }
}
