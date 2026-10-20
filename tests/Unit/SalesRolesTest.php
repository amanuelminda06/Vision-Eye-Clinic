<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Roles;
use App\Support\Sales\Constants;
use App\Support\Services\Categories;
use PHPUnit\Framework\TestCase;

/**
 * Fixed-key validation and role predicates for the services/sales modules,
 * ported from src/modules/services/categories.ts, src/modules/sales/roles.ts
 * and src/modules/sales/constants.ts.
 */
class SalesRolesTest extends TestCase
{
    public function test_service_categories_are_the_fixed_ten_keys_in_order(): void
    {
        $this->assertSame([
            'consultation', 'refraction', 'diagnostics', 'tests', 'glasses',
            'optical', 'contact_lenses', 'specialist', 'surgery', 'other',
        ], Categories::SERVICE_CATEGORIES);
    }

    public function test_is_service_category_guards_unknown_values(): void
    {
        $this->assertTrue(Categories::isServiceCategory('consultation'));
        $this->assertFalse(Categories::isServiceCategory('nope'));
        $this->assertFalse(Categories::isServiceCategory(null));
        $this->assertFalse(Categories::isServiceCategory(42));
    }

    public function test_every_category_has_a_label_key(): void
    {
        foreach (Categories::SERVICE_CATEGORIES as $category) {
            $this->assertArrayHasKey($category, Categories::CATEGORY_LABEL_KEYS);
        }
    }

    public function test_shows_revenue_only_to_the_owner(): void
    {
        $this->assertTrue(Roles::canViewRevenue('OWNER'));
        $this->assertFalse(Roles::canViewRevenue('RECEPTION'));
        $this->assertFalse(Roles::canViewRevenue('STAFF'));
        $this->assertFalse(Roles::canViewRevenue(null));
    }

    public function test_lets_only_the_owner_approve_or_void_directly(): void
    {
        $this->assertTrue(Roles::canApproveVoid('OWNER'));
        $this->assertFalse(Roles::canApproveVoid('RECEPTION'));
        $this->assertTrue(Roles::canVoidDirectly('OWNER'));
        $this->assertFalse(Roles::canVoidDirectly('RECEPTION'));
    }

    public function test_lets_owner_and_reception_request_a_void_never_staff(): void
    {
        $this->assertTrue(Roles::canRequestVoid('OWNER'));
        $this->assertTrue(Roles::canRequestVoid('RECEPTION'));
        $this->assertFalse(Roles::canRequestVoid('STAFF'));
    }

    public function test_scopes_sales_visibility_to_the_owner(): void
    {
        $this->assertTrue(Roles::canSeeAllSales('OWNER'));
        $this->assertFalse(Roles::canSeeAllSales('RECEPTION'));
        $this->assertFalse(Roles::canSeeAllSales('STAFF'));
    }

    public function test_payment_methods_and_statuses_are_guarded(): void
    {
        $this->assertSame(['CASH', 'MOBILE_MONEY', 'BANK', 'CARD', 'OTHER'], Constants::PAYMENT_METHODS);
        $this->assertSame(['DRAFT', 'CONFIRMED', 'VOIDED'], Constants::SALE_STATUSES);

        $this->assertTrue(Constants::isPaymentMethod('CARD'));
        $this->assertFalse(Constants::isPaymentMethod('BITCOIN'));
        $this->assertTrue(Constants::isSaleStatus('VOIDED'));
        $this->assertFalse(Constants::isSaleStatus('PENDING'));
    }

    public function test_label_keys_fall_back_to_the_raw_value_when_unknown(): void
    {
        $this->assertSame('payCash', Constants::paymentLabelKey('CASH'));
        $this->assertSame('MOBILE_MONEY_UNKNOWN', Constants::paymentLabelKey('MOBILE_MONEY_UNKNOWN'));
        $this->assertSame('statusConfirmed', Constants::statusLabelKey('CONFIRMED'));
        $this->assertSame('catConsultation', Categories::labelKey('consultation'));
        $this->assertSame('mystery', Categories::labelKey('mystery'));
    }
}
