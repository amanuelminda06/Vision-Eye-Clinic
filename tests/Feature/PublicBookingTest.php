<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Service;
use App\Support\ClinicTime;
use App\Support\RateLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAppointments;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The public booking intake (POST /api/public/requests).
 *
 * The booking is auto-approved: a CONFIRMED appointment with a server-assigned
 * doctor. Defences: honeypot, per-IP rate limit, strict validation, and only
 * the sha-256 IP hash is stored.
 */
class PublicBookingTest extends TestCase
{
    use CreatesAppointments;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimit::reset();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Website Visitor',
            'phone' => '0911223344',
            'preferredDate' => ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), 3),
            'preferredTimeWindow' => 'any',
            'serviceInterest' => '',
            'message' => 'I would like an eye check-up.',
            'language' => 'en',
            'consent' => true,
            'website' => '',
        ], $overrides);
    }

    public function test_a_valid_booking_creates_a_confirmed_appointment(): void
    {
        $doctor = $this->createDoctor(['full_name' => 'Dr. Abebe']);

        $response = $this->postJson('/api/public/requests', $this->payload());

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('appointment.date', $this->payload()['preferredDate']);
        $response->assertJsonPath('appointment.time', '09:00');
        $response->assertJsonPath('appointment.doctor', 'Dr. Abebe');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $appointment = Appointment::query()->firstOrFail();

        $this->assertSame(Appointment::STATUS_CONFIRMED, (string) $appointment->status);
        $this->assertSame(Appointment::SOURCE_WEBSITE, (string) $appointment->created_from);
        $this->assertSame('public-booking', (string) $appointment->created_by);
        $this->assertSame($doctor->id, $appointment->doctor_id);
    }

    public function test_a_booking_creates_the_patient_when_the_phone_is_unknown(): void
    {
        $this->createDoctor();

        $this->postJson('/api/public/requests', $this->payload())->assertOk();

        $patient = Patient::query()->firstOrFail();

        $this->assertSame('Website Visitor', $patient->full_name);
        $this->assertSame('+251911223344', $patient->phone_e164);
        $this->assertSame('public-booking', (string) $patient->created_by);
    }

    public function test_a_booking_reuses_an_existing_patient(): void
    {
        $this->createDoctor();
        $existing = Patient::factory()->create([
            'created_by' => $this->createUser(['role' => 'OWNER'])->id,
            'phone_e164' => '+251911223344',
            'full_name' => 'Known Person',
        ]);

        $this->postJson('/api/public/requests', $this->payload())->assertOk();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame($existing->id, Appointment::query()->firstOrFail()->patient_id);
    }

    public function test_a_matching_service_interest_links_the_service(): void
    {
        $this->createDoctor();
        $service = Service::factory()->create(['name' => 'Eye Consultation', 'active' => true]);

        $this->postJson('/api/public/requests', $this->payload([
            'serviceInterest' => 'eye consultation',
        ]))->assertOk();

        $this->assertSame($service->id, Appointment::query()->firstOrFail()->service_id);
    }

    public function test_an_unmatched_service_interest_is_kept_in_the_notes(): void
    {
        $this->createDoctor();

        $this->postJson('/api/public/requests', $this->payload([
            'serviceInterest' => 'Laser eye surgery',
        ]))->assertOk();

        $notes = (string) Appointment::query()->firstOrFail()->notes;

        $this->assertStringContainsString('Service of interest: Laser eye surgery', $notes);
    }

    public function test_an_unparseable_body_is_a_400(): void
    {
        $this->call('POST', '/api/public/requests', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not json at all')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalidJson');
    }

    public function test_a_missing_consent_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload(['consent' => false]));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validationError');
        $response->assertJsonPath('fields.consent', 'consentRequired');
    }

    public function test_a_bad_date_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload(['preferredDate' => '01-11-2026']));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.preferredDate', 'invalidDate');
    }

    public function test_an_unknown_time_window_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload(['preferredTimeWindow' => 'midnight']));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.preferredTimeWindow', 'invalidTimeWindow');
    }

    public function test_an_unparseable_phone_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload(['phone' => '12345']));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.phone', 'invalidPhone');
    }

    public function test_a_date_in_the_past_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload([
            'preferredDate' => ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), -3),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.preferredDate', 'invalidDate');
    }

    public function test_a_date_too_far_ahead_is_a_422(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload([
            'preferredDate' => ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), 200),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.preferredDate', 'invalidDate');
    }

    public function test_a_past_slot_today_is_a_422(): void
    {
        // "any" maps to 09:00 — past that time the slot is gone.
        $now = ClinicTime::nowUtc();
        $past = $now->getTimestamp() > ClinicTime::combineDateAndTime(
            ClinicTime::clinicDateKey($now, self::TZ),
            '09:00',
            self::TZ,
        )->getTimestamp();

        if (! $past) {
            $this->markTestSkipped('The 09:00 slot has not passed yet today.');
        }

        $response = $this->postJson('/api/public/requests', $this->payload([
            'preferredDate' => ClinicTime::clinicDateKey($now, self::TZ),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('fields.preferredDate', 'pastSlot');
    }

    public function test_a_filled_honeypot_is_rejected_as_spam(): void
    {
        $this->createDoctor();

        // The booking schema runs BEFORE the honeypot drop, so a filled
        // honeypot is a 422 (spec §3.4/§11.2) — the silent-drop branch only
        // exists on the feedback route.
        $response = $this->postJson('/api/public/requests', $this->payload(['website' => 'http://spam.example']));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validationError');
        $response->assertJsonPath('fields.website', 'spam');

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_without_an_active_doctor_the_booking_is_a_503(): void
    {
        $response = $this->postJson('/api/public/requests', $this->payload());

        $response->assertStatus(503);
        $response->assertJsonPath('error', 'noDoctorAvailable');
    }

    public function test_the_rate_limit_blocks_the_sixth_submission(): void
    {
        $this->createDoctor();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/requests', $this->payload([
                'phone' => '091122334'.$i,
            ]))->assertOk();
        }

        $response = $this->postJson('/api/public/requests', $this->payload([
            'phone' => '0911223399',
        ]));

        $response->assertStatus(429);
        $response->assertJsonPath('error', 'rateLimited');
    }

    public function test_the_audit_never_stores_the_raw_ip(): void
    {
        $this->createDoctor();

        $this->withHeaders(['x-forwarded-for' => '203.0.113.9, 10.0.0.1'])
            ->postJson('/api/public/requests', $this->payload())
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'APPOINTMENT_CREATED')->firstOrFail();

        $this->assertSame(hash('sha256', '203.0.113.9'), $audit->new_json['ipHash']);
        $this->assertStringNotContainsString('203.0.113.9', (string) json_encode($audit->new_json));
        $this->assertNull($audit->ip);
    }
}
