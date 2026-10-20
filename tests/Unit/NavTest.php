<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Nav;
use App\Support\Roles;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sidebar module visibility per role (legacy NAV_MODULES + rule 14: empty
 * modules are dropped) and the active-link rules used by the layout.
 */
class NavTest extends TestCase
{
    /** @return list<string> */
    private static function hrefs(string $role): array
    {
        $hrefs = [];

        foreach (Nav::visibleFor($role) as $module) {
            foreach ($module['items'] as $item) {
                $hrefs[] = $item['href'];
            }
        }

        return $hrefs;
    }

    private function bindRequestPath(string $path): void
    {
        $this->app->instance('request', Request::create($path));
    }

    public function test_owner_sees_every_module(): void
    {
        $keys = array_column(Nav::visibleFor(Roles::OWNER), 'key');
        $this->assertSame(['dashboard', 'pms', 'hrm', 'mms', 'cms', 'audit'], $keys);

        $hrefs = self::hrefs(Roles::OWNER);
        $this->assertContains('/revenue', $hrefs);
        $this->assertContains('/settings', $hrefs);
        $this->assertContains('/audit', $hrefs);
        $this->assertContains('/patients', $hrefs);
        $this->assertContains('/messages', $hrefs);
        $this->assertCount(20, $hrefs);
    }

    public function test_reception_sees_frontdesk_sections_but_not_owner_only_ones(): void
    {
        $hrefs = self::hrefs(Roles::RECEPTION);

        $this->assertContains('/patients', $hrefs);
        $this->assertContains('/sales', $hrefs);
        $this->assertContains('/appointments', $hrefs);
        $this->assertContains('/messages', $hrefs);
        $this->assertContains('/schedule', $hrefs);
        $this->assertContains('/leave/calendar', $hrefs);

        $this->assertNotContains('/revenue', $hrefs);
        $this->assertNotContains('/settings', $hrefs);
        $this->assertNotContains('/audit', $hrefs);
        $this->assertNotContains('/staff', $hrefs);
        $this->assertNotContains('/users', $hrefs);
        $this->assertNotContains('/dashboard/daily', $hrefs);
        $this->assertNotContains('/dashboard/services', $hrefs);
        // Legacy NAV_MODULES: feedback is OWNER-only.
        $this->assertNotContains('/dashboard/feedback', $hrefs);
    }

    public function test_staff_sees_only_own_things(): void
    {
        $hrefs = self::hrefs(Roles::STAFF);

        $this->assertSame(['/dashboard', '/leave', '/schedule'], $hrefs);
    }

    public function test_modules_with_no_visible_items_are_dropped(): void
    {
        $keys = array_column(Nav::visibleFor(Roles::STAFF), 'key');
        $this->assertSame(['dashboard', 'hrm'], $keys);
        $this->assertNotContains('mms', $keys);
        $this->assertNotContains('pms', $keys);
        $this->assertNotContains('cms', $keys);
        $this->assertNotContains('audit', $keys);
    }

    public function test_active_link_matching(): void
    {
        $this->bindRequestPath('/dashboard');
        $this->assertTrue(Nav::isActive('/dashboard'));
        $this->assertFalse(Nav::isActive('/patients'));

        $this->bindRequestPath('/patients/123');
        $this->assertTrue(Nav::isActive('/patients'));
        $this->assertFalse(Nav::isActive('/patients2'));

        $this->bindRequestPath('/sales');
        $this->assertTrue(Nav::isActive('/sales'));

        // /dashboard never acts as a parent (it is its own section).
        $this->bindRequestPath('/dashboard/services');
        $this->assertFalse(Nav::isActive('/dashboard'));
    }
}
