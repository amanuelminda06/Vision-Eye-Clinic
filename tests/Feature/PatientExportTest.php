<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Patients\Csv;
use App\Support\Patients\PatientImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Patient CSV/XLSX export and the import template, ported from the export and
 * template API routes.
 *
 * Both stream an attachment with `Cache-Control: no-store`. The export is
 * filter-aware, capped and audited (EXPORTED); the template is not audited and
 * carries the import columns plus one example row.
 */
class PatientExportTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    public function test_csv_export_streams_an_attachment_with_a_header_row(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Exported Patient', 'phone_e164' => '+251911223344'], $user);

        $response = $this->actingAs($user)->get('/patients/export?format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        // The framework's session middleware appends `private`; the download
        // itself must still forbid caching.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="patients-', (string) $response->headers->get('Content-Disposition'));

        $body = $response->getContent();
        $this->assertStringContainsString('Exported Patient', $body);
        $this->assertStringContainsString('+251911223344', $body);
        // Header row is written exactly once, with the legacy column order.
        $this->assertStringContainsString('id,idNo,fullName,phone,sex,birthDate,preferredLanguage,tags,doNotMessage,active,createdAt', $body);
    }

    public function test_csv_export_includes_each_patients_tags(): void
    {
        $user = $this->owner();
        $patient = $this->createPatient(['full_name' => 'Tagged Export'], $user);
        $a = $this->createTag('glasses');
        $b = $this->createTag('cataract');
        $patient->tags()->attach([$a->id, $b->id]);

        $body = $this->actingAs($user)->get('/patients/export?format=csv')->assertOk()->getContent();

        $this->assertStringContainsString('cataract; glasses', $body);
    }

    public function test_the_export_records_an_exported_audit(): void
    {
        $user = $this->owner();
        $this->createPatient([], $user);

        $this->actingAs($user)->get('/patients/export?format=csv')->assertOk();

        $log = AuditLog::where('action', 'EXPORTED')->sole();
        $this->assertSame('patient', $log->entity);
        $this->assertSame('csv', $log->new_json['format']);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_the_export_honours_the_active_status_filter(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Active One', 'active' => true], $user);
        $this->createPatient(['full_name' => 'Inactive One', 'active' => false], $user);

        $body = $this->actingAs($user)->get('/patients/export?format=csv&status=true')->assertOk()->getContent();

        $this->assertStringContainsString('Active One', $body);
        $this->assertStringNotContainsString('Inactive One', $body);
    }

    public function test_the_export_honours_the_name_query(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Specific Name'], $user);
        $this->createPatient(['full_name' => 'Another Person'], $user);

        $body = $this->actingAs($user)->get('/patients/export?format=csv&q=Specific')->assertOk()->getContent();

        $this->assertStringContainsString('Specific Name', $body);
        $this->assertStringNotContainsString('Another Person', $body);
    }

    public function test_xlsx_export_returns_a_spreadsheet_attachment(): void
    {
        $user = $this->owner();
        $this->createPatient(['full_name' => 'Sheet Patient'], $user);

        $response = $this->actingAs($user)->get('/patients/export?format=xlsx')->assertOk();

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
        // A real ZIP container starts with the PK signature.
        $this->assertSame('PK', substr($response->getContent(), 0, 2));
    }

    public function test_an_unknown_format_falls_back_to_csv(): void
    {
        $user = $this->owner();
        $this->createPatient([], $user);

        $this->actingAs($user)
            ->get('/patients/export?format=pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_the_template_carries_the_import_columns_and_an_example_row(): void
    {
        $body = $this->actingAs($this->owner())->get('/patients/template')->assertOk()->getContent();

        $this->assertStringContainsString(implode(',', PatientImport::IMPORT_COLUMNS), $body);
        $this->assertStringContainsString('Example Patient', $body);
        $this->assertStringContainsString('0912345678', $body);
    }

    public function test_the_template_is_not_audited(): void
    {
        $this->actingAs($this->owner())->get('/patients/template')->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_template_round_trips_through_the_importer(): void
    {
        // The generated template must parse cleanly: header + one valid row.
        $body = $this->actingAs($this->owner())->get('/patients/template')->assertOk()->getContent();
        $rows = Csv::parseCsv($body);

        $this->assertSame(PatientImport::IMPORT_COLUMNS, $rows[0]);
        $parsed = PatientImport::parseImport($body, ['defaultLanguage' => 'en', 'existingPhones' => []]);
        $this->assertSame([], $parsed['missingColumns']);
        $this->assertSame(1, $parsed['validCount']);
        $this->assertSame(0, $parsed['errorCount']);
    }

    public function test_reception_can_export_but_staff_cannot(): void
    {
        $reception = $this->createUser(['role' => 'RECEPTION']);
        $this->createPatient([], $reception);

        $this->actingAs($reception)->get('/patients/export')->assertOk();

        $this->actingAs($this->createUser(['role' => 'STAFF']))
            ->get('/patients/export')
            ->assertRedirect('/403');
    }
}
