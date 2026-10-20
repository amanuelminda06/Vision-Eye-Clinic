<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ClinicTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHr;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The public "on duty now" feed.
 *
 * It needs no session and returns a strict allow-list projection: no phone, no
 * email, no leave reasons, no shift notes, no internal record ids.
 */
class PublicDoctorsTest extends TestCase
{
    use CreatesHr;
    use CreatesUsers;
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    public function test_the_feed_is_public_and_needs_no_session(): void
    {
        $response = $this->getJson('/api/public/doctors')->assertOk();

        $response->assertJsonStructure(['doctors', 'generatedAt']);

        // Symfony re-orders the directives, so assert on each one.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=120', $cacheControl);
    }

    public function test_only_published_active_doctors_are_listed(): void
    {
        $published = $this->createStaff(['full_name' => 'Published Doctor', 'is_doctor' => true, 'show_on_website' => true]);
        $this->createStaff(['full_name' => 'Hidden Doctor', 'is_doctor' => true, 'show_on_website' => false]);
        $this->createStaff(['full_name' => 'Inactive Doctor', 'is_doctor' => true, 'show_on_website' => true, 'active' => false]);
        $this->createStaff(['full_name' => 'Not A Doctor', 'is_doctor' => false, 'show_on_website' => true]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $names = array_column($response->json('doctors'), 'name');

        $this->assertSame(['Published Doctor'], $names);
        $this->assertNotContains('Hidden Doctor', $names);
        $this->assertNotContains('Inactive Doctor', $names);
        $this->assertNotContains('Not A Doctor', $names);
    }

    public function test_a_running_shift_reports_on_duty_now(): void
    {
        $doctor = $this->createStaff(['full_name' => 'On Duty', 'is_doctor' => true, 'show_on_website' => true]);
        $todayKey = ClinicTime::clinicDateKey(now(), self::TZ);

        $this->createShift([
            'staff_id' => $doctor->id,
            'dateKey' => $todayKey,
            'startTime' => '00:00',
            'endTime' => '23:59',
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $this->assertSame('ON_DUTY_NOW', $response->json('doctors.0.status'));
        $this->assertNotEmpty($response->json('doctors.0.todayShifts'));
    }

    public function test_an_approved_leave_wins_over_a_running_shift(): void
    {
        $doctor = $this->createStaff(['full_name' => 'On Leave', 'is_doctor' => true, 'show_on_website' => true]);
        $todayKey = ClinicTime::clinicDateKey(now(), self::TZ);

        $this->createShift([
            'staff_id' => $doctor->id,
            'dateKey' => $todayKey,
            'startTime' => '00:00',
            'endTime' => '23:59',
        ]);

        $this->createLeaveRequest([
            'staff_id' => $doctor->id,
            'status' => 'APPROVED',
            'start_date' => $todayKey,
            'end_date' => $todayKey,
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $this->assertSame('ON_LEAVE', $response->json('doctors.0.status'));
    }

    public function test_a_pending_leave_does_not_suppress_the_duty_status(): void
    {
        $doctor = $this->createStaff(['full_name' => 'Still Working', 'is_doctor' => true, 'show_on_website' => true]);
        $todayKey = ClinicTime::clinicDateKey(now(), self::TZ);

        $this->createShift([
            'staff_id' => $doctor->id,
            'dateKey' => $todayKey,
            'startTime' => '00:00',
            'endTime' => '23:59',
        ]);

        $this->createLeaveRequest([
            'staff_id' => $doctor->id,
            'status' => 'REQUESTED',
            'start_date' => $todayKey,
            'end_date' => $todayKey,
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $this->assertSame('ON_DUTY_NOW', $response->json('doctors.0.status'));
    }

    public function test_the_feed_never_leaks_private_fields(): void
    {
        $doctor = $this->createStaff([
            'full_name' => 'Strict Doctor',
            'is_doctor' => true,
            'show_on_website' => true,
            'phone' => '+251911223344',
            'annual_leave_days' => 20,
        ]);

        $this->createLeaveRequest([
            'staff_id' => $doctor->id,
            'status' => 'APPROVED',
            'reason' => 'Private medical reason',
        ]);

        $body = (string) $this->getJson('/api/public/doctors')->getContent();

        foreach ([
            'phone', '+251911223344', 'annualLeaveDays', 'annual_leave_days',
            'reason', 'Private medical reason', 'jobTitle', 'job_title',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_each_doctor_entry_has_exactly_the_public_keys(): void
    {
        $doctor = $this->createStaff(['is_doctor' => true, 'show_on_website' => true]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $entry = $response->json('doctors.0');
        $keys = array_keys($entry);
        sort($keys);

        $this->assertSame([
            'id', 'name', 'nextShiftStartsAt', 'photoUrl', 'specialty', 'status', 'todayShifts',
        ], $keys);
    }

    public function test_the_today_shifts_use_the_clinic_local_time(): void
    {
        $doctor = $this->createStaff(['is_doctor' => true, 'show_on_website' => true]);
        $todayKey = ClinicTime::clinicDateKey(now(), self::TZ);

        $this->createShift([
            'staff_id' => $doctor->id,
            'dateKey' => $todayKey,
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $this->assertSame(
            [['start' => '09:00', 'end' => '17:00']],
            $response->json('doctors.0.todayShifts'),
        );
    }

    public function test_the_next_shift_start_is_an_iso_instant(): void
    {
        $doctor = $this->createStaff(['is_doctor' => true, 'show_on_website' => true]);
        $tomorrow = ClinicTime::dateKeyAdd(ClinicTime::clinicDateKey(now(), self::TZ), 1);

        $this->createShift([
            'staff_id' => $doctor->id,
            'dateKey' => $tomorrow,
            'startTime' => '09:00',
            'endTime' => '17:00',
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $next = $response->json('doctors.0.nextShiftStartsAt');

        $this->assertIsString($next);
        $this->assertNotFalse(strtotime((string) $next));
    }
}
