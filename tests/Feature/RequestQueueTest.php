<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AuditLog;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The website request queue (the front desk).
 *
 * Rules preserved:
 * - Only OWNER and RECEPTION see the queue.
 * - A request is converted into a SCHEDULED appointment; the visitor is
 *   matched by phone (or the patient is created) at that moment.
 * - A converted request can never be converted again.
 * - Nothing is hard-deleted: the status is the state.
 */
class RequestQueueTest extends TestCase
{
    use CreatesAppointments;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    private int $phoneCounter = 0;

    private function request(array $overrides = []): AppointmentRequest
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $row = AppointmentRequest::create(array_merge([
            'name' => 'Website Visitor',
            'phone_e164' => '+251911'.str_pad((string) (++$this->phoneCounter), 6, '0', STR_PAD_LEFT),
            'preferred_date' => ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), 3),
            'preferred_time_window' => 'morning',
            'language' => 'en',
            'status' => AppointmentRequest::STATUS_NEW,
            'ip_hash' => str_repeat('a', 64),
            'created_by' => $owner->id,
        ], $overrides));

        return $row;
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_owner_and_reception_can_open_the_queue(): void
    {
        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->get('/requests')->assertOk();

        $this->actingAs($this->createUser(['role' => Roles::RECEPTION]))
            ->get('/requests')->assertOk();
    }

    public function test_staff_is_blocked_from_the_queue(): void
    {
        $this->actingAs($this->createUser(['role' => Roles::STAFF]))
            ->get('/requests')->assertRedirect('/403');
    }

    public function test_a_signed_out_visitor_is_redirected_to_the_login(): void
    {
        $this->get('/requests')->assertRedirect();
    }

    // ---------------------------------------------------------------------
    // The list
    // ---------------------------------------------------------------------

    public function test_the_queue_lists_the_requests_oldest_first(): void
    {
        $first = $this->request(['name' => 'First Visitor']);
        $second = $this->request(['name' => 'Second Visitor']);

        $response = $this->actingAs($this->createUser(['role' => Roles::RECEPTION]))->get('/requests');

        $response->assertOk();
        $response->assertSee('First Visitor');
        $response->assertSee('Second Visitor');

        $body = $response->getContent();
        $this->assertLessThan(
            strpos((string) $body, 'Second Visitor'),
            strpos((string) $body, 'First Visitor'),
            'The oldest request must come first.',
        );
    }

    public function test_the_queue_can_be_filtered_by_status(): void
    {
        $this->request(['name' => 'New Visitor', 'status' => AppointmentRequest::STATUS_NEW]);
        $this->request(['name' => 'Spam Visitor', 'status' => AppointmentRequest::STATUS_SPAM]);

        $response = $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->get('/requests?status=SPAM');

        $response->assertOk();
        $response->assertSee('Spam Visitor');
        $response->assertDontSee('New Visitor');
    }

    public function test_the_new_counter_counts_only_the_new_requests(): void
    {
        $this->request(['status' => AppointmentRequest::STATUS_NEW]);
        $this->request(['status' => AppointmentRequest::STATUS_NEW]);
        $this->request(['status' => AppointmentRequest::STATUS_CONTACTED]);

        $response = $this->actingAs($this->createUser(['role' => Roles::OWNER]))->get('/requests');

        $response->assertOk();
        $response->assertSee('2');
    }

    public function test_the_sidebar_shows_the_new_counter(): void
    {
        $this->request(['status' => AppointmentRequest::STATUS_NEW]);
        $this->request(['status' => AppointmentRequest::STATUS_NEW]);
        $this->request(['status' => AppointmentRequest::STATUS_CONTACTED]);

        $response = $this->actingAs($this->createUser(['role' => Roles::OWNER]))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('/requests');
        // The badge carries the NEW count.
        $this->assertMatchesRegularExpression(
            '/href="\/requests".*?bg-red-500.*?>2</s',
            (string) $response->getContent(),
        );
    }

    // ---------------------------------------------------------------------
    // Converting
    // ---------------------------------------------------------------------

    public function test_converting_creates_a_scheduled_appointment(): void
    {
        $doctor = $this->createDoctor(['full_name' => 'Dr. Abebe']);
        $request = $this->request([
            'preferred_date' => '2026-10-13',
            'preferred_time_window' => 'morning',
        ]);

        $response = $this->actingAs($this->createUser(['role' => Roles::RECEPTION]))
            ->post("/requests/{$request->id}/convert", [
                'doctorId' => $doctor->id,
            ]);

        $response->assertRedirect();

        $appointment = Appointment::query()->firstOrFail();

        $this->assertSame(Appointment::STATUS_SCHEDULED, (string) $appointment->status);
        $this->assertSame(Appointment::SOURCE_WEBSITE, (string) $appointment->created_from);
        $this->assertSame($doctor->id, $appointment->doctor_id);
        // The morning window starts at 09:00 clinic time.
        $this->assertSame('2026-10-13 06:00:00', $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 06:30:00', $appointment->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_converting_creates_the_patient_when_the_phone_is_unknown(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request(['name' => 'Brand New Visitor']);

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertRedirect();

        $patient = Patient::query()->firstOrFail();

        $this->assertSame('Brand New Visitor', (string) $patient->full_name);
        $this->assertSame('en', (string) $patient->preferred_language);
    }

    public function test_converting_links_an_existing_patient_with_the_same_phone(): void
    {
        $doctor = $this->createDoctor();
        $existing = Patient::factory()->create([
            'created_by' => $this->createUser(['role' => Roles::OWNER])->id,
            'phone_e164' => '+251911000001',
            'full_name' => 'Known Person',
        ]);

        $request = $this->request(['phone_e164' => '+251911000001']);

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertRedirect();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame($existing->id, Appointment::query()->firstOrFail()->patient_id);
    }

    public function test_converting_marks_the_request_as_converted(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(AppointmentRequest::STATUS_CONVERTED, (string) $request->status);
        $this->assertNotNull($request->appointment_id);
        $this->assertNotNull($request->patient_id);
        $this->assertNotNull($request->handled_by);
        $this->assertNotNull($request->handled_at);
    }

    public function test_converting_is_audited(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id]);

        $audit = AuditLog::query()->where('action', 'REQUEST_CONVERTED')->firstOrFail();

        $this->assertSame('AppointmentRequest', (string) $audit->entity);
        $this->assertSame($request->id, (string) $audit->entity_id);
        $this->assertSame(AppointmentRequest::STATUS_CONVERTED, $audit->new_json['status']);
        $this->assertArrayHasKey('appointmentId', $audit->new_json);
    }

    public function test_converting_prepares_the_appointment_messages(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request([
            'preferred_date' => ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), 5),
        ]);

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id]);

        $this->assertGreaterThan(0, MessageTask::query()->count());
    }

    public function test_a_converted_request_can_never_be_converted_again(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request();

        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertRedirect();

        $this->actingAs($reception)
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertRedirect();

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_converting_without_a_doctor_is_refused(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => ''])
            ->assertSessionHasErrors('doctorId');

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(AppointmentRequest::STATUS_NEW, (string) $request->fresh()->status);
    }

    public function test_converting_with_an_inactive_doctor_is_refused(): void
    {
        $doctor = $this->createDoctor(['active' => false]);
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id])
            ->assertSessionHasErrors('doctorId');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_converting_with_a_non_doctor_is_refused(): void
    {
        $staff = Staff::factory()->create([
            'is_doctor' => false,
            'active' => true,
        ]);
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", ['doctorId' => $staff->id])
            ->assertSessionHasErrors('doctorId');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_converting_keeps_the_chosen_service_and_notes(): void
    {
        $doctor = $this->createDoctor();
        $service = Service::factory()->create(['name' => 'Eye Consultation', 'active' => true]);
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/convert", [
                'doctorId' => $doctor->id,
                'serviceId' => $service->id,
                'notes' => 'Called the visitor.',
            ])
            ->assertRedirect();

        $appointment = Appointment::query()->firstOrFail();

        $this->assertSame($service->id, $appointment->service_id);
        $this->assertSame('Called the visitor.', (string) $appointment->notes);
    }

    // ---------------------------------------------------------------------
    // The status actions
    // ---------------------------------------------------------------------

    public function test_a_request_can_be_marked_as_contacted(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::RECEPTION]))
            ->post("/requests/{$request->id}/status", ['status' => 'CONTACTED'])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(AppointmentRequest::STATUS_CONTACTED, (string) $request->status);
        $this->assertNotNull($request->handled_by);
        $this->assertNotNull($request->handled_at);
    }

    public function test_a_request_can_be_declined(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/status", ['status' => 'DECLINED'])
            ->assertRedirect();

        $this->assertSame(AppointmentRequest::STATUS_DECLINED, (string) $request->fresh()->status);
    }

    public function test_a_request_can_be_marked_as_spam(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/status", ['status' => 'SPAM'])
            ->assertRedirect();

        $this->assertSame(AppointmentRequest::STATUS_SPAM, (string) $request->fresh()->status);
    }

    public function test_the_status_change_is_audited(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/status", ['status' => 'SPAM']);

        $audit = AuditLog::query()->where('action', 'REQUEST_STATUS_CHANGED')->firstOrFail();

        $this->assertSame(AppointmentRequest::STATUS_NEW, $audit->old_json['status']);
        $this->assertSame(AppointmentRequest::STATUS_SPAM, $audit->new_json['status']);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post("/requests/{$request->id}/status", ['status' => 'CONVERTED'])
            ->assertSessionHasErrors('status');

        $this->assertSame(AppointmentRequest::STATUS_NEW, (string) $request->fresh()->status);
    }

    public function test_a_converted_request_can_not_be_re_statused(): void
    {
        $doctor = $this->createDoctor();
        $request = $this->request();
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)
            ->post("/requests/{$request->id}/convert", ['doctorId' => $doctor->id]);

        $this->actingAs($reception)
            ->post("/requests/{$request->id}/status", ['status' => 'DECLINED'])
            ->assertRedirect();

        $this->assertSame(AppointmentRequest::STATUS_CONVERTED, (string) $request->fresh()->status);
    }

    public function test_staff_can_not_change_a_request(): void
    {
        $request = $this->request();

        $this->actingAs($this->createUser(['role' => Roles::STAFF]))
            ->post("/requests/{$request->id}/status", ['status' => 'SPAM'])
            ->assertForbidden();

        $this->assertSame(AppointmentRequest::STATUS_NEW, (string) $request->fresh()->status);
    }

    public function test_an_unknown_request_is_a_404(): void
    {
        $this->actingAs($this->createUser(['role' => Roles::OWNER]))
            ->post('/requests/does-not-exist/status', ['status' => 'SPAM'])
            ->assertNotFound();
    }
}
