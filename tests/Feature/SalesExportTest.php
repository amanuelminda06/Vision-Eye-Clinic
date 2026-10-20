<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Patients\Csv;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Sales CSV/XLSX export: the OWNER gets the full filtered list, RECEPTION is
 * scoped to their own sales of the current clinic day. Every download is
 * audited as EXPORTED.
 */
class SalesExportTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_csv_export_streams_an_attachment_with_the_legacy_header_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);
        $patient = $this->createPatient(['full_name' => 'Exported Patient']);

        $sale = $this->createSale([
            'created_by' => $owner->id,
            'patient_id' => $patient->id,
            'total' => '600.00',
        ], [
            ['service' => $service, 'qty' => 2],
        ]);

        $response = $this->actingAs($owner)->get('/sales/export?format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="sales-', (string) $response->headers->get('Content-Disposition'));

        $body = $response->getContent();

        $this->assertStringContainsString(
            'receiptNumber,saleDate,patient,doctor,paymentMethod,status,lines,total,createdBy',
            $body,
        );
        $this->assertStringContainsString($sale->receipt_number, $body);
        $this->assertStringContainsString('Exported Patient', $body);
        $this->assertStringContainsString('600.00', $body);
    }

    public function test_the_export_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/sales/export?format=csv')->assertOk();

        $audit = AuditLog::query()->where('action', 'EXPORTED')->firstOrFail();

        $this->assertSame('sale', $audit->entity);
        $this->assertSame('export', $audit->entity_id);
        $this->assertSame('csv', $audit->new_json['format']);
    }

    public function test_xlsx_export_streams_a_spreadsheet(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();
        $this->createSale(['created_by' => $owner->id], [['service' => $service, 'qty' => 1]]);

        $response = $this->actingAs($owner->fresh())->get('/sales/export?format=xlsx')->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        // XLSX is a zip container — it starts with "PK".
        $this->assertSame('PK', substr((string) $response->getContent(), 0, 2));
    }

    public function test_the_export_respects_the_date_filter(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $today = $this->createSale(['created_by' => $owner->id], [['service' => $service, 'qty' => 1]]);
        $old = $this->createSale(
            ['created_by' => $owner->id, 'sale_date' => now()->subDays(10)],
            [['service' => $service, 'qty' => 1]],
        );

        $body = $this->actingAs($owner->fresh())
            ->get('/sales/export?format=csv&from='.now()->format('Y-m-d'))
            ->getContent();

        $this->assertStringContainsString($today->receipt_number, $body);
        $this->assertStringNotContainsString($old->receipt_number, $body);
    }

    public function test_reception_export_is_scoped_to_their_own_sales_of_today(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);
        $service = $this->createService();

        $mine = $this->createSale(['created_by' => $reception->id], [['service' => $service, 'qty' => 1]]);
        $theirs = $this->createSale(['created_by' => $other->id], [['service' => $service, 'qty' => 1]]);
        $older = $this->createSale(
            ['created_by' => $reception->id, 'sale_date' => now()->subDays(5)],
            [['service' => $service, 'qty' => 1]],
        );

        $body = $this->actingAs($reception)->get('/sales/export?format=csv')->getContent();

        $this->assertStringContainsString($mine->receipt_number, $body);
        $this->assertStringNotContainsString($theirs->receipt_number, $body);
        $this->assertStringNotContainsString($older->receipt_number, $body);
    }

    public function test_reception_cannot_widen_the_scope_with_a_date_filter(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);
        $service = $this->createService();

        $theirs = $this->createSale(['created_by' => $other->id], [['service' => $service, 'qty' => 1]]);

        // Even asking for a wide range, the scope stays "own sales of today".
        $body = $this->actingAs($reception)
            ->get('/sales/export?format=csv&from=2000-01-01&to=2099-12-31')
            ->getContent();

        $this->assertStringNotContainsString($theirs->receipt_number, $body);
    }

    public function test_an_unauthenticated_export_redirects_to_the_login(): void
    {
        $this->get('/sales/export?format=csv')->assertRedirect(login_path());
    }

    public function test_the_csv_body_parses_back_into_rows(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '300.00']);
        $sale = $this->createSale(['created_by' => $owner->id, 'total' => '600.00'], [
            ['service' => $service, 'qty' => 2],
        ]);

        $body = $this->actingAs($owner->fresh())->get('/sales/export?format=csv')->getContent();
        $rows = Csv::parseCsv($body);

        $this->assertSame(
            ['receiptNumber', 'saleDate', 'patient', 'doctor', 'paymentMethod', 'status', 'lines', 'total', 'createdBy'],
            $rows[0],
        );
        $this->assertCount(2, $rows);
        $this->assertSame($sale->receipt_number, $rows[1][0]);
        $this->assertSame('600.00', $rows[1][7]);
    }
}
