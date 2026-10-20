<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Patients\Csv;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The appointment CSV/XLSX export: the legacy column order, the filters and
 * the EXPORTED audit.
 */
class AppointmentExportTest extends TestCase
{
    use CreatesAppointments;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_csv_export_streams_an_attachment_with_the_legacy_header_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $response = $this->actingAs($owner)->get('/appointments/export?format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="appointments-', (string) $response->headers->get('Content-Disposition'));

        $body = $response->getContent();

        $this->assertStringContainsString(
            'id,startsAt,endsAt,patient,patientPhone,doctor,service,status,createdFrom,notes',
            $body,
        );
        $this->assertStringContainsString($appointment->id, $body);
        $this->assertStringContainsString($appointment->patient->full_name, $body);
    }

    public function test_the_export_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/appointments/export?format=csv')->assertOk();

        $audit = AuditLog::query()->where('action', 'EXPORTED')->firstOrFail();

        $this->assertSame('appointment', $audit->entity);
        $this->assertSame('export', $audit->entity_id);
        $this->assertSame('csv', $audit->new_json['format']);
    }

    public function test_the_export_respects_the_doctor_filter(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $other = $this->createDoctor();

        $mine = $this->createAppointment(['doctor_id' => $doctor->id]);
        $theirs = $this->createAppointment(['doctor_id' => $other->id]);

        $body = $this->actingAs($owner->fresh())
            ->get('/appointments/export?format=csv&doctorId='.$doctor->id)
            ->getContent();

        $this->assertStringContainsString($mine->id, $body);
        $this->assertStringNotContainsString($theirs->id, $body);
    }

    public function test_the_export_respects_the_status_filter(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $confirmed = $this->createAppointment(['status' => 'CONFIRMED']);
        $cancelled = $this->createAppointment(['status' => 'CANCELLED']);

        $body = $this->actingAs($owner->fresh())
            ->get('/appointments/export?format=csv&status=CANCELLED')
            ->getContent();

        $this->assertStringContainsString($cancelled->id, $body);
        $this->assertStringNotContainsString($confirmed->id, $body);
    }

    public function test_xlsx_export_streams_a_spreadsheet(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createAppointment();

        $response = $this->actingAs($owner->fresh())->get('/appointments/export?format=xlsx')->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertSame('PK', substr((string) $response->getContent(), 0, 2));
    }

    public function test_an_unauthenticated_export_redirects_to_the_login(): void
    {
        $this->get('/appointments/export?format=csv')->assertRedirect(login_path());
    }

    public function test_staff_cannot_export(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staff)->get('/appointments/export?format=csv')->assertRedirect('/403');
    }

    public function test_the_csv_body_parses_back_into_rows(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $body = $this->actingAs($owner->fresh())->get('/appointments/export?format=csv')->getContent();
        $rows = Csv::parseCsv($body);

        $this->assertSame(
            ['id', 'startsAt', 'endsAt', 'patient', 'patientPhone', 'doctor', 'service', 'status', 'createdFrom', 'notes'],
            $rows[0],
        );
        $this->assertCount(2, $rows);
        $this->assertSame($appointment->id, $rows[1][0]);
    }
}
