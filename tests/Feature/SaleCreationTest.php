<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Service;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Creating a sale: server-side price recomputation, the price snapshot, the
 * gap-free receipt number and every referential guard.
 */
class SaleCreationTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_it_creates_a_confirmed_sale_with_a_gap_free_receipt_number(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $response = $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 2]]),
        ]);

        $response->assertRedirect();

        $sale = Sale::query()->firstOrFail();

        $this->assertSame(Sale::STATUS_CONFIRMED, $sale->status);
        $this->assertSame('600.00', Money::toDecimalString($sale->total));
        $this->assertSame($owner->id, $sale->created_by);
        $this->assertNotNull($sale->confirmed_at);
        $this->assertNull($sale->patient_id);

        $year = (int) substr(ClinicTime::clinicDateKey($sale->sale_date, 'Africa/Addis_Ababa'), 0, 4);
        $this->assertSame(sprintf('%d-000001', $year), $sale->receipt_number);
    }

    public function test_the_receipt_numbers_are_sequential_and_gap_free(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner->fresh())->post('/sales', [
                'paymentMethod' => 'CASH',
                'walkIn' => 'true',
                'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
            ])->assertRedirect();
        }

        $numbers = Sale::query()->orderBy('receipt_number')->pluck('receipt_number')->all();

        $this->assertCount(3, $numbers);
        $this->assertSame(
            [substr($numbers[0], 0, 5).'000001', substr($numbers[0], 0, 5).'000002', substr($numbers[0], 0, 5).'000003'],
            $numbers,
        );
    }

    public function test_a_failed_sale_frees_the_receipt_number(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        // A sale whose line refers to a missing service fails AFTER the counter
        // was claimed — the transaction rolls back, so no gap appears.
        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => 'does-not-exist', 'qty' => 1]]),
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, Sale::query()->count());

        $this->actingAs($owner->fresh())->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $sale = Sale::query()->firstOrFail();
        $this->assertStringEndsWith('-000001', $sale->receipt_number);
    }

    public function test_the_server_price_ignores_a_tampered_client_price(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            // A client that tries to post its own price must be ignored.
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1, 'unitPrice' => '1.00', 'lineTotal' => '1.00']]),
        ])->assertRedirect();

        $line = SaleLine::query()->firstOrFail();

        $this->assertSame('300.00', Money::toDecimalString($line->unit_price));
        $this->assertSame('300.00', Money::toDecimalString($line->line_total));
        $this->assertSame('300.00', Money::toDecimalString(Sale::query()->firstOrFail()->total));
    }

    public function test_the_line_snapshots_the_service_name_and_price(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['name' => 'Eye Consultation', 'name_en' => 'Eye Consultation', 'current_price' => '300.00']);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $line = SaleLine::query()->firstOrFail();
        $this->assertSame('Eye Consultation', $line->service_name_snapshot);

        // A later price change never touches the existing line.
        $service->forceFill(['current_price' => '500.00', 'name' => 'Renamed', 'name_en' => 'Renamed'])->save();

        $line->refresh();
        $this->assertSame('300.00', Money::toDecimalString($line->unit_price));
        $this->assertSame('Eye Consultation', $line->service_name_snapshot);
    }

    public function test_duplicate_service_rows_merge_by_summing_the_quantity(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([
                ['serviceId' => $service->id, 'qty' => 1],
                ['serviceId' => $service->id, 'qty' => 2],
            ]),
        ])->assertRedirect();

        $this->assertSame(1, SaleLine::query()->count());
        $this->assertSame(3, SaleLine::query()->firstOrFail()->qty);
        $this->assertSame('900.00', Money::toDecimalString(Sale::query()->firstOrFail()->total));
    }

    public function test_it_rejects_an_inactive_service(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['active' => false]);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_it_requires_at_least_one_line(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([]),
        ])->assertSessionHasErrors('lines');
    }

    public function test_unparseable_lines_are_treated_as_no_lines(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => 'not json',
        ])->assertSessionHasErrors('lines');
    }

    public function test_the_payment_method_must_be_known(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'BITCOIN',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('paymentMethod');
    }

    public function test_a_walk_in_sale_needs_no_patient(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $this->assertNull(Sale::query()->firstOrFail()->patient_id);
    }

    public function test_a_linked_sale_needs_an_existing_active_patient(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('patientId');

        $this->actingAs($owner->fresh())->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'patientId' => 'nope',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('patientId');

        $inactive = $this->createPatient(['active' => false]);

        $this->actingAs($owner->fresh())->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'patientId' => $inactive->id,
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('patientId');
    }

    public function test_a_linked_sale_stores_the_patient(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'patientId' => $patient->id,
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $this->assertSame($patient->id, Sale::query()->firstOrFail()->patient_id);
    }

    public function test_an_inactive_doctor_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();
        $doctor = Staff::create([
            'full_name' => 'Dr. Test',
            'job_title' => 'Ophthalmologist',
            'is_doctor' => true,
            'active' => false,
        ]);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'doctorId' => $doctor->id,
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertSessionHasErrors('doctorId');
    }

    public function test_an_active_doctor_is_stored(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();
        $doctor = Staff::create([
            'full_name' => 'Dr. Test',
            'job_title' => 'Ophthalmologist',
            'is_doctor' => true,
            'active' => true,
        ]);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'doctorId' => $doctor->id,
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $this->assertSame($doctor->id, Sale::query()->firstOrFail()->doctor_id);
    }

    public function test_the_sale_audit_records_the_money_values(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '1500.50']);

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CARD',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $audit = AuditLog::query()->where('action', 'SALE_CREATED')->firstOrFail();

        $this->assertSame('Sale', $audit->entity);
        $this->assertSame('CARD', $audit->new_json['paymentMethod']);
        $this->assertSame('CONFIRMED', $audit->new_json['status']);
        $this->assertSame('1500.50', $audit->new_json['total']);
        $this->assertSame($service->name, $audit->new_json['lines'][0]['service']);
    }

    public function test_the_sale_is_never_hard_deleted(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'true',
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $sale = Sale::query()->firstOrFail();

        // There is no delete route for a sale at all.
        $this->post("/sales/{$sale->id}/delete")->assertNotFound();
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_the_patient_detail_sales_tab_lists_the_sale(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/sales', [
            'paymentMethod' => 'CASH',
            'walkIn' => 'false',
            'patientId' => $patient->id,
            'lines' => json_encode([['serviceId' => $service->id, 'qty' => 1]]),
        ])->assertRedirect();

        $response = $this->actingAs($owner->fresh())->get("/patients/{$patient->id}?tab=sales");

        $response->assertOk();
        $response->assertSee(Sale::query()->firstOrFail()->receipt_number);
    }
}
