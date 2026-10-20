<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Server-side access control for the slice-3 surfaces.
 *
 * - `/dashboard/services*` and `/revenue` are OWNER-only → RECEPTION/STAFF get
 *   the /403 redirect on a GET, a hard 403 on a mutation.
 * - `/sales*` is OWNER + RECEPTION → STAFF is blocked.
 * - RECEPTION sees only their own sales of the current clinic day.
 */
class SalesAccessTest extends TestCase
{
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_anonymous_requests_redirect_to_the_secret_login(): void
    {
        $this->get('/sales')->assertRedirect(login_path());
        $this->get('/sales/new')->assertRedirect(login_path());
        $this->get('/sales/export')->assertRedirect(login_path());
        $this->get('/revenue')->assertRedirect(login_path());
        $this->get('/dashboard/services')->assertRedirect(login_path());
        $this->post('/sales')->assertRedirect(login_path());
    }

    public function test_owner_can_open_every_money_surface(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/sales')->assertOk();
        $this->actingAs($owner->fresh())->get('/sales/new')->assertOk();
        $this->actingAs($owner->fresh())->get('/revenue')->assertOk();
        $this->actingAs($owner->fresh())->get('/dashboard/services')->assertOk();
        $this->actingAs($owner->fresh())->get('/dashboard/services/new')->assertOk();
        $this->actingAs($owner->fresh())->get('/sales/voids')->assertOk();
    }

    public function test_reception_can_open_the_sales_surfaces_but_not_revenue(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)->get('/sales')->assertOk();
        $this->actingAs($reception->fresh())->get('/sales/new')->assertOk();

        // Wrong role on a GET → redirect to /403 (not a hard 403).
        $this->actingAs($reception->fresh())->get('/revenue')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->get('/dashboard/services')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->get('/sales/voids')->assertRedirect('/403');
    }

    public function test_staff_is_blocked_from_every_money_surface(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staff)->get('/sales')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/sales/new')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/revenue')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/dashboard/services')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/sales/voids')->assertRedirect('/403');
    }

    public function test_staff_mutations_are_a_hard_403(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);
        $service = $this->createService();

        $this->actingAs($staff)->post('/sales', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post('/dashboard/services', [])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/dashboard/services/{$service->id}", [])->assertForbidden();
        $this->actingAs($staff->fresh())->post("/dashboard/services/{$service->id}/status", [])->assertForbidden();
    }

    public function test_reception_cannot_mutate_the_service_catalogue(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $service = $this->createService();

        $this->actingAs($reception)->post('/dashboard/services', [])->assertForbidden();
        $this->actingAs($reception->fresh())->post("/dashboard/services/{$service->id}", [])->assertForbidden();
        $this->actingAs($reception->fresh())->post("/dashboard/services/{$service->id}/status", [])->assertForbidden();
    }

    public function test_reception_cannot_void_or_approve_directly(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($reception)->post("/sales/{$sale->id}/void", ['reason' => 'Because'])
            ->assertForbidden();
        $this->actingAs($reception->fresh())->post("/sales/{$sale->id}/void-approve", [])
            ->assertForbidden();
        $this->actingAs($reception->fresh())->post("/sales/{$sale->id}/void-reject", ['reason' => 'No'])
            ->assertForbidden();
    }

    public function test_reception_sees_only_their_own_sales_of_today(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);

        $mine = $this->createSale(['created_by' => $reception->id]);
        $theirs = $this->createSale(['created_by' => $other->id]);
        $older = $this->createSale([
            'created_by' => $reception->id,
            'sale_date' => now()->subDays(3),
        ]);

        $response = $this->actingAs($reception)->get('/sales');

        $response->assertOk();
        $response->assertSee($mine->receipt_number);
        $response->assertDontSee($theirs->receipt_number);
        $response->assertDontSee($older->receipt_number);
    }

    public function test_reception_cannot_open_a_sale_created_by_someone_else(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $other->id]);

        $this->actingAs($reception)->get("/sales/{$sale->id}")->assertNotFound();
    }

    public function test_reception_can_open_their_own_sale(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($reception)->get("/sales/{$sale->id}")->assertOk();
    }

    public function test_owner_can_open_any_sale(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($owner)->get("/sales/{$sale->id}")->assertOk();
    }

    public function test_the_staff_nav_hides_every_money_surface(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $response = $this->actingAs($staff)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('href="/sales"', false);
        $response->assertDontSee('href="/revenue"', false);
        $response->assertDontSee('href="/dashboard/services"', false);
    }

    public function test_the_owner_nav_shows_the_money_surfaces(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="/sales"', false);
        $response->assertSee('href="/revenue"', false);
        $response->assertSee('href="/dashboard/services"', false);
    }

    public function test_the_reception_nav_hides_revenue_but_shows_sales(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $response = $this->actingAs($reception)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="/sales"', false);
        $response->assertDontSee('href="/revenue"', false);
    }

    public function test_a_missing_sale_is_a_404_for_the_owner(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/sales/01J0000000000000000000000')->assertNotFound();
    }

    public function test_creating_a_sale_writes_one_audit_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 2]]),
        ])->assertRedirect();

        $audit = AuditLog::query()
            ->where('entity', 'Sale')
            ->where('action', 'SALE_CREATED')
            ->firstOrFail();

        $this->assertSame('600.00', $audit->new_json['total']);
        $this->assertCount(1, $audit->new_json['lines']);
        $this->assertSame(2, $audit->new_json['lines'][0]['qty']);
        // The three money values on a line are rendered with formatMoney, while
        // the sale total is a plain decimal string (legacy asymmetry).
        $this->assertSame('ETB 300.00', $audit->new_json['lines'][0]['unitPrice']);
        $this->assertSame('ETB 600.00', $audit->new_json['lines'][0]['lineTotal']);
    }
}
