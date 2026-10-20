<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The sales list: filters, the fixed newest-first order, the pagination cap
 * and the aggregate total (including the service-filtered variant).
 */
class SalesListTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_the_list_shows_the_newest_sale_first(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $old = $this->createSale(
            ['created_by' => $owner->id, 'sale_date' => now()->subDays(2)],
            [['service' => $service, 'qty' => 1]],
        );
        $new = $this->createSale(
            ['created_by' => $owner->id, 'sale_date' => now()],
            [['service' => $service, 'qty' => 1]],
        );

        $body = $this->actingAs($owner)->get('/sales')->getContent();

        $this->assertLessThan(
            strpos($body, $old->receipt_number),
            strpos($body, $new->receipt_number),
        );
    }

    public function test_the_patient_filter_matches_the_name(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $wanted = $this->createPatient(['full_name' => 'Zewdibu Ermias']);
        $other = $this->createPatient(['full_name' => 'Kalkidan Bekele']);

        $mine = $this->createSale(
            ['created_by' => $owner->id, 'patient_id' => $wanted->id],
            [['service' => $service, 'qty' => 1]],
        );
        $theirs = $this->createSale(
            ['created_by' => $owner->id, 'patient_id' => $other->id],
            [['service' => $service, 'qty' => 1]],
        );

        $body = $this->actingAs($owner->fresh())->get('/sales?patient=Zewdibu')->getContent();

        $this->assertStringContainsString($mine->receipt_number, $body);
        $this->assertStringNotContainsString($theirs->receipt_number, $body);
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $confirmed = $this->createSale(['created_by' => $owner->id], [['service' => $service, 'qty' => 1]]);
        $voided = $this->createSale(
            ['created_by' => $owner->id, 'status' => 'VOIDED'],
            [['service' => $service, 'qty' => 1]],
        );

        $body = $this->actingAs($owner->fresh())->get('/sales?status=VOIDED')->getContent();

        $this->assertStringContainsString($voided->receipt_number, $body);
        $this->assertStringNotContainsString($confirmed->receipt_number, $body);
    }

    public function test_an_unknown_filter_value_is_dropped(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $sale = $this->createSale(['created_by' => $owner->id], [['service' => $service, 'qty' => 1]]);

        // A bogus status must not filter everything away.
        $body = $this->actingAs($owner->fresh())->get('/sales?status=BOGUS&from=not-a-date')->getContent();

        $this->assertStringContainsString($sale->receipt_number, $body);
    }

    public function test_the_aggregate_total_is_shown_for_the_owner(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale(['created_by' => $owner->id, 'total' => '300.00'], [['service' => $service, 'qty' => 1]]);
        $this->createSale(['created_by' => $owner->id, 'total' => '600.00'], [['service' => $service, 'qty' => 2]]);

        $response = $this->actingAs($owner->fresh())->get('/sales');

        $response->assertOk();
        $response->assertSee('ETB 900.00');
    }

    public function test_filtering_by_a_service_sums_only_that_service(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $consultation = $this->createService(['name' => 'Consult', 'name_en' => 'Consult', 'current_price' => '300.00']);
        $scan = $this->createService(['name' => 'Scan', 'name_en' => 'Scan', 'current_price' => '1500.50']);

        // One sale with both services: crediting the whole total to the
        // consultation would be wrong.
        $this->createSale(['created_by' => $owner->id, 'total' => '1800.50'], [
            ['service' => $consultation, 'qty' => 1],
            ['service' => $scan, 'qty' => 1],
        ]);

        $response = $this->actingAs($owner->fresh())->get("/sales?serviceId={$consultation->id}");

        $response->assertOk();
        // The aggregate line sums only the consultation line (300.00), not the
        // whole sale total (1,800.50) — which the row itself still shows.
        $response->assertSee('Total ETB 300.00');
        $response->assertSee('ETB 1,800.50');
    }

    public function test_reception_does_not_see_the_filter_card_or_the_total(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->createSale(['created_by' => $reception->id, 'total' => '300.00'], [['service' => $service, 'qty' => 1]]);

        $response = $this->actingAs($reception)->get('/sales');

        $response->assertOk();
        $response->assertDontSee('name="from"', false);
        $response->assertDontSee('name="status"', false);
        $response->assertDontSee('format=csv', false);
        // No aggregate total line — the row totals themselves still show.
        $response->assertDontSee('Total ETB 300.00');
    }

    public function test_the_owner_sees_the_filter_card_and_the_export_links(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->get('/sales');

        $response->assertOk();
        $response->assertSee('name="from"', false);
        $response->assertSee('name="status"', false);
        $response->assertSee('format=csv', false);
    }
}
