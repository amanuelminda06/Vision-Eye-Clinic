<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The void flow: RECEPTION/OWNER request, the OWNER approves or rejects, the
 * OWNER voids directly. Only CONFIRMED sales can be voided and a confirmed
 * sale is otherwise immutable.
 */
class SaleVoidTest extends TestCase
{
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_the_owner_voids_a_confirmed_sale_directly(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Wrong amount charged'])
            ->assertRedirect();

        $sale->refresh();

        $this->assertSame(Sale::STATUS_VOIDED, $sale->status);
        $this->assertSame($owner->id, $sale->voided_by);
        $this->assertSame('Wrong amount charged', $sale->void_reason);
        $this->assertNotNull($sale->voided_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Sale',
            'entity_id' => $sale->id,
            'action' => 'SALE_VOIDED',
        ]);
    }

    public function test_the_void_reason_must_be_at_least_three_characters(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void", ['reason' => 'ab'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->fresh()->status);
    }

    public function test_the_void_reason_is_capped_at_300_characters(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void", ['reason' => str_repeat('x', 301)])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->fresh()->status);
    }

    public function test_an_already_voided_sale_cannot_be_voided_again(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id, 'status' => Sale::STATUS_VOIDED]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Again'])
            ->assertSessionHas('actionError', 'notConfirmed');
    }

    public function test_reception_files_a_void_request(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($reception)
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'Customer returned the glasses'])
            ->assertRedirect();

        $sale->refresh();

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->status);
        $this->assertSame($reception->id, $sale->void_requested_by);
        $this->assertSame('Customer returned the glasses', $sale->void_request_reason);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Sale',
            'entity_id' => $sale->id,
            'action' => 'SALE_VOID_REQUESTED',
        ]);
    }

    public function test_only_one_void_request_is_allowed_at_a_time(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($reception)
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'First request'])
            ->assertRedirect();

        $this->actingAs($reception->fresh())
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'Second request'])
            ->assertSessionHas('actionError', 'alreadyRequested');
    }

    public function test_the_owner_approves_a_void_request_and_reuses_its_reason(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($reception)
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'Customer returned the glasses'])
            ->assertRedirect();

        $this->actingAs($owner->fresh())
            ->post("/sales/{$sale->id}/void-approve")
            ->assertRedirect('/sales/voids');

        $sale->refresh();

        $this->assertSame(Sale::STATUS_VOIDED, $sale->status);
        $this->assertSame($owner->id, $sale->voided_by);
        $this->assertSame('Customer returned the glasses', $sale->void_reason);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Sale',
            'entity_id' => $sale->id,
            'action' => 'SALE_VOID_APPROVED',
        ]);
    }

    public function test_approving_without_a_pending_request_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void-approve")
            ->assertSessionHas('actionError', 'noRequest');

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->fresh()->status);
    }

    public function test_the_owner_rejects_a_void_request_and_the_sale_stays_confirmed(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);

        $this->actingAs($reception)
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'Customer returned the glasses'])
            ->assertRedirect();

        $this->actingAs($owner->fresh())
            ->post("/sales/{$sale->id}/void-reject", ['reason' => 'The sale was correct'])
            ->assertRedirect();

        $sale->refresh();

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->status);
        $this->assertNull($sale->void_requested_by);
        $this->assertNull($sale->void_request_reason);
        $this->assertNull($sale->voided_by);

        $audit = AuditLog::query()->where('action', 'SALE_VOID_REQUEST_REJECTED')->firstOrFail();
        $this->assertSame('The sale was correct', $audit->new_json['rejectReason']);
        // The reject text is stored in the audit only — never on the sale row.
        $this->assertNull($sale->void_reason);
    }

    public function test_rejecting_without_a_pending_request_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $sale = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($owner)
            ->post("/sales/{$sale->id}/void-reject", ['reason' => 'Nothing to reject'])
            ->assertSessionHas('actionError', 'noRequest');
    }

    public function test_the_void_inbox_lists_the_pending_requests(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $sale = $this->createSale(['created_by' => $reception->id]);
        $other = $this->createSale(['created_by' => $owner->id]);

        $this->actingAs($reception)
            ->post("/sales/{$sale->id}/void-request", ['reason' => 'Customer returned the glasses'])
            ->assertRedirect();

        $response = $this->actingAs($owner->fresh())->get('/sales/voids');

        $response->assertOk();
        $response->assertSee($sale->receipt_number);
        $response->assertDontSee($other->receipt_number);
    }

    public function test_a_voided_sale_is_excluded_from_the_revenue_total(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        $this->createSale(['created_by' => $owner->id, 'total' => '100.00'], [
            ['service' => $service, 'qty' => 1],
        ]);
        $voided = $this->createSale(['created_by' => $owner->id, 'total' => '900.00'], [
            ['service' => $service, 'qty' => 9],
        ]);

        $this->actingAs($owner->fresh())
            ->post("/sales/{$voided->id}/void", ['reason' => 'Wrong amount charged'])
            ->assertRedirect();

        $response = $this->actingAs($owner->fresh())->get('/revenue?period=today');

        $response->assertOk();
        // The confirmed 100.00 counts; the voided 900.00 only shows in the
        // separate voided card.
        $response->assertSee('ETB 100.00');
        $response->assertSee('ETB 900.00');
    }
}
