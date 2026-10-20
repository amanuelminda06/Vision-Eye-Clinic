<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The revenue dashboard (OWNER only): period presets, the totals, the
 * breakdowns and the voided card.
 */
class RevenueReportTest extends TestCase
{
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_it_shows_the_totals_for_the_selected_period(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale(['created_by' => $owner->id, 'total' => '300.00'], [
            ['service' => $service, 'qty' => 1],
        ]);
        $this->createSale(['created_by' => $owner->id, 'total' => '600.00'], [
            ['service' => $service, 'qty' => 2],
        ]);

        $response = $this->actingAs($owner)->get('/revenue?period=today');

        $response->assertOk();
        $response->assertSee('ETB 900.00');
        $response->assertSee(__('sales.totalRevenue'));
    }

    public function test_draft_sales_never_count(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale([
            'created_by' => $owner->id,
            'status' => 'DRAFT',
            'total' => '300.00',
        ], [['service' => $service, 'qty' => 1]]);

        $response = $this->actingAs($owner->fresh())->get('/revenue?period=today');

        $response->assertOk();
        $response->assertSee('ETB 0.00');
        $response->assertDontSee('ETB 300.00');
    }

    public function test_voided_sales_are_reported_separately(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale([
            'created_by' => $owner->id,
            'status' => 'VOIDED',
            'total' => '900.00',
        ], [['service' => $service, 'qty' => 3]]);

        $response = $this->actingAs($owner->fresh())->get('/revenue?period=today');

        $response->assertOk();
        // The confirmed total stays zero; the voided card shows 900.00.
        $response->assertSee('ETB 900.00');
        $response->assertSee(__('sales.voidedTotal'));
    }

    public function test_the_breakdowns_are_rendered(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService([
            'name' => 'OCT Scan',
            'name_en' => 'OCT Scan',
            'category' => 'diagnostics',
            'current_price' => '1500.50',
        ]);

        $this->createSale(['created_by' => $owner->id, 'total' => '1500.50'], [
            ['service' => $service, 'qty' => 1],
        ]);

        $response = $this->actingAs($owner->fresh())->get('/revenue?period=today');

        $response->assertOk();
        $response->assertSee(__('sales.byService'));
        $response->assertSee(__('sales.byCategory'));
        $response->assertSee(__('sales.byDoctor'));
        $response->assertSee(__('sales.byPaymentMethod'));
        $response->assertSee('OCT Scan');
        $response->assertSee(__('services.catDiagnostics'));
    }

    public function test_an_unknown_period_falls_back_to_today(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->get('/revenue?period=bogus');

        $response->assertOk();
        $response->assertSee(__('sales.periodToday'));
    }

    public function test_the_custom_period_uses_the_given_range(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale([
            'created_by' => $owner->id,
            'sale_date' => now()->subDays(2),
            'total' => '300.00',
        ], [['service' => $service, 'qty' => 1]]);

        $from = now()->subDays(3)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->actingAs($owner->fresh())->get("/revenue?period=custom&from={$from}&to={$to}");

        $response->assertOk();
        $response->assertSee('ETB 300.00');
    }

    public function test_the_daily_trend_is_zero_filled(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale([
            'created_by' => $owner->id,
            'sale_date' => now()->subDays(1),
            'total' => '300.00',
        ], [['service' => $service, 'qty' => 1]]);

        $from = now()->subDays(2)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->actingAs($owner->fresh())->get("/revenue?period=custom&from={$from}&to={$to}");

        $response->assertOk();
        $response->assertSee($from);
        $response->assertSee($to);
    }

    public function test_the_revenue_page_is_owner_only(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)->get('/revenue')->assertRedirect('/403');
    }
}
