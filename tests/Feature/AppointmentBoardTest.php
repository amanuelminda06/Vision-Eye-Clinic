<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The appointment board: creating, editing, the status graph, the terminal
 * lock, the cancel reason and the double-booking warning.
 */
class AppointmentBoardTest extends TestCase
{
    use CreatesAppointments;
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    public function test_the_owner_creates_an_appointment(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor(['full_name' => 'Dr. Abebe']);
        $patient = $this->createPatient(['full_name' => 'Zewdibu Ermias']);

        $this->actingAs($owner)->post('/appointments', [
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => '30',
            'serviceId' => '',
            'notes' => 'Routine check-up',
        ])->assertRedirect();

        $appointment = Appointment::query()->firstOrFail();

        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame($doctor->id, $appointment->doctor_id);
        $this->assertSame(Appointment::STATUS_SCHEDULED, (string) $appointment->status);
        $this->assertSame(Appointment::SOURCE_STAFF, (string) $appointment->created_from);
        $this->assertSame('Routine check-up', $appointment->notes);
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-08', '09:00', self::TZ)->getTimestamp(),
            $appointment->starts_at->getTimestamp(),
        );
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-08', '09:30', self::TZ)->getTimestamp(),
            $appointment->ends_at->getTimestamp(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Appointment',
            'entity_id' => $appointment->id,
            'action' => 'APPOINTMENT_CREATED',
        ]);
    }

    public function test_a_patient_is_required(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();

        $this->actingAs($owner)->post('/appointments', [
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => '30',
        ])->assertSessionHasErrors('patientId');
    }

    public function test_the_doctor_must_be_an_active_doctor(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $notADoctor = Staff::factory()->create(['is_doctor' => false, 'active' => true]);
        $inactive = $this->createDoctor(['active' => false]);

        foreach ([$notADoctor->id, $inactive->id] as $doctorId) {
            $this->actingAs($owner->fresh())->post('/appointments', [
                'patientId' => $patient->id,
                'doctorId' => $doctorId,
                'dateKey' => '2026-10-08',
                'startTime' => '09:00',
                'durationMin' => '30',
            ])->assertSessionHas('actionError', 'doctorRequired');
        }
    }

    public function test_the_duration_must_be_between_15_and_180_minutes(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();

        foreach (['5', '200', 'abc'] as $duration) {
            $this->actingAs($owner->fresh())->post('/appointments', [
                'patientId' => $patient->id,
                'doctorId' => $doctor->id,
                'dateKey' => '2026-10-08',
                'startTime' => '09:00',
                'durationMin' => $duration,
            ])->assertSessionHasErrors('durationMin');
        }
    }

    public function test_the_start_time_must_be_a_valid_24_hour_time(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();

        $this->actingAs($owner)->post('/appointments', [
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '25:00',
            'durationMin' => '30',
        ])->assertSessionHasErrors('startTime');
    }

    public function test_a_quick_created_patient_is_reused_when_the_phone_exists(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient(['full_name' => 'Existing Person', 'phone_e164' => '+251911223344']);

        $this->actingAs($owner)->post('/appointments', [
            'quickName' => 'Existing Person',
            'quickPhone' => '0911223344',
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => '30',
        ])->assertRedirect();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame($patient->id, Appointment::query()->firstOrFail()->patient_id);
    }

    public function test_a_quick_created_patient_is_created_when_new(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();

        $this->actingAs($owner)->post('/appointments', [
            'quickName' => 'Brand New Person',
            'quickPhone' => '0911229988',
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => '30',
        ])->assertRedirect();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame('+251911229988', Appointment::query()->firstOrFail()->patient->phone_e164);
    }

    public function test_a_double_booking_is_a_warning_not_a_blocker(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();

        $this->createAppointment([
            'doctor_id' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => 45,
        ]);

        // Without the override the appointment is not written, but the warning
        // is shown.
        $this->actingAs($owner)->post('/appointments', [
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:15',
            'durationMin' => '30',
        ])->assertSessionHas('doubleBooked', true);

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_the_owner_can_save_anyway_and_it_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();

        $this->createAppointment([
            'doctor_id' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => 45,
        ]);

        $this->actingAs($owner)->post('/appointments', [
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-08',
            'startTime' => '09:15',
            'durationMin' => '30',
            'overrideDoubleBooking' => 'true',
        ])->assertRedirect();

        $this->assertSame(2, Appointment::query()->count());

        $audit = AuditLog::query()->where('action', 'APPOINTMENT_DOUBLE_BOOKED')->firstOrFail();
        $this->assertSame(1, $audit->new_json['clashCount']);
    }

    public function test_a_status_transition_follows_the_graph(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $this->actingAs($owner)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'CONFIRMED',
        ])->assertRedirect();

        $this->assertSame(Appointment::STATUS_CONFIRMED, (string) $appointment->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Appointment',
            'entity_id' => $appointment->id,
            'action' => 'APPOINTMENT_STATUS_CHANGED',
        ]);
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $this->actingAs($owner)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'COMPLETED',
        ])->assertSessionHas('actionError', 'invalidTransition');

        $this->assertSame(Appointment::STATUS_SCHEDULED, (string) $appointment->fresh()->status);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $this->actingAs($owner)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'CANCELLED',
        ])->assertSessionHas('actionError', 'cancelReasonRequired');

        $this->assertSame(Appointment::STATUS_SCHEDULED, (string) $appointment->fresh()->status);
    }

    public function test_cancelling_stores_the_reason(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $this->actingAs($owner)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'CANCELLED',
            'cancelReason' => 'Patient is unwell',
        ])->assertRedirect();

        $appointment->refresh();

        $this->assertSame(Appointment::STATUS_CANCELLED, (string) $appointment->status);
        $this->assertSame('Patient is unwell', $appointment->cancel_reason);
    }

    public function test_a_terminal_appointment_is_frozen_for_reception(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $appointment = $this->createAppointment(['status' => Appointment::STATUS_COMPLETED]);

        $this->actingAs($reception)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'CONFIRMED',
        ])->assertSessionHas('actionError', 'terminalLocked');

        $this->assertSame(Appointment::STATUS_COMPLETED, (string) $appointment->fresh()->status);
    }

    public function test_the_owner_may_move_out_of_a_terminal_state(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment(['status' => Appointment::STATUS_COMPLETED]);

        $this->actingAs($owner)->post("/appointments/{$appointment->id}/status", [
            'id' => $appointment->id,
            'status' => 'CONFIRMED',
        ])->assertRedirect();

        $this->assertSame(Appointment::STATUS_CONFIRMED, (string) $appointment->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Appointment',
            'entity_id' => $appointment->id,
            'action' => 'APPOINTMENT_STATUS_CHANGED',
        ]);
    }

    public function test_a_terminal_appointment_cannot_be_edited_by_reception(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();
        $appointment = $this->createAppointment([
            'status' => Appointment::STATUS_COMPLETED,
            'doctor_id' => $doctor->id,
        ]);

        $this->actingAs($reception)->post("/appointments/{$appointment->id}", [
            'id' => $appointment->id,
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-09',
            'startTime' => '10:00',
            'durationMin' => '30',
        ])->assertSessionHas('actionError', 'terminalLocked');
    }

    public function test_the_owner_edits_an_appointment(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $doctor = $this->createDoctor();
        $patient = $this->createPatient();
        $appointment = $this->createAppointment(['doctor_id' => $doctor->id]);

        $this->actingAs($owner)->post("/appointments/{$appointment->id}", [
            'id' => $appointment->id,
            'patientId' => $patient->id,
            'doctorId' => $doctor->id,
            'dateKey' => '2026-10-09',
            'startTime' => '10:00',
            'durationMin' => '45',
        ])->assertRedirect();

        $appointment->refresh();

        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame(
            ClinicTime::combineDateAndTime('2026-10-09', '10:00', self::TZ)->getTimestamp(),
            $appointment->starts_at->getTimestamp(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Appointment',
            'entity_id' => $appointment->id,
            'action' => 'APPOINTMENT_UPDATED',
        ]);
    }

    public function test_the_board_shows_the_appointments(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $appointment = $this->createAppointment();

        $response = $this->actingAs($owner)->get('/appointments');

        $response->assertOk();
        $response->assertSee($appointment->patient->full_name);
    }

    public function test_the_day_view_only_shows_that_day(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $today = ClinicTime::clinicDateKey(now(), self::TZ);
        $other = ClinicTime::dateKeyAdd($today, 3);

        $todayAppointment = $this->createAppointment(['dateKey' => $today]);
        $otherAppointment = $this->createAppointment(['dateKey' => $other]);

        $body = $this->actingAs($owner->fresh())
            ->get('/appointments?view=day&day='.$today)
            ->getContent();

        $this->assertStringContainsString($todayAppointment->patient->full_name, $body);
        $this->assertStringNotContainsString($otherAppointment->patient->full_name, $body);
    }

    public function test_an_unknown_view_falls_back_to_the_list(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $response = $this->actingAs($owner)->get('/appointments?view=bogus');

        $response->assertOk();
        $response->assertSee(__('appointments.viewList'));
    }
}
