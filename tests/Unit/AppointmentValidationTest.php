<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Appointments\ApptRoles;
use App\Support\Appointments\ApptValidation;
use App\Support\Appointments\Constants;
use App\Support\PublicSite\BookingValidation;
use PHPUnit\Framework\TestCase;

/**
 * Appointment, follow-up and public-booking form validation, ported from
 * src/modules/appointments/schemas.ts and src/modules/public-site/schemas.ts.
 */
class AppointmentValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validAppointment(): array
    {
        return [
            'patientId' => 'pat-1',
            'doctorId' => 'doc-1',
            'dateKey' => '2026-10-08',
            'startTime' => '09:00',
            'durationMin' => '30',
            'serviceId' => '',
            'notes' => '',
        ];
    }

    public function test_a_valid_appointment_form_is_accepted(): void
    {
        $result = ApptValidation::appointmentForm($this->validAppointment());

        $this->assertSame([], $result['fields']);
        $this->assertSame('doc-1', $result['values']['doctorId']);
        $this->assertSame(30, $result['values']['durationMin']);
        $this->assertNull($result['values']['serviceId']);
        $this->assertFalse($result['values']['overrideDoubleBooking']);
    }

    public function test_the_doctor_is_required(): void
    {
        $values = $this->validAppointment();
        $values['doctorId'] = '';

        $this->assertSame('doctorRequired', ApptValidation::appointmentForm($values)['fields']['doctorId']);
    }

    public function test_the_duration_must_be_between_15_and_180(): void
    {
        foreach (['5', '200', 'abc'] as $duration) {
            $values = $this->validAppointment();
            $values['durationMin'] = $duration;

            $this->assertSame('invalidDuration', ApptValidation::appointmentForm($values)['fields']['durationMin'], $duration);
        }
    }

    public function test_a_patient_or_a_quick_create_is_required(): void
    {
        $values = $this->validAppointment();
        $values['patientId'] = '';

        $this->assertSame('patientRequired', ApptValidation::appointmentForm($values)['fields']['patientId']);

        $values['quickName'] = 'New Person';
        // A quick-create needs BOTH a name and a phone.
        $this->assertSame('patientRequired', ApptValidation::appointmentForm($values)['fields']['patientId']);

        $values['quickPhone'] = '0911223344';
        $this->assertSame([], ApptValidation::appointmentForm($values)['fields']);
    }

    public function test_the_notes_are_capped_at_300_characters(): void
    {
        $values = $this->validAppointment();
        $values['notes'] = str_repeat('x', 301);

        $this->assertSame('tooLong', ApptValidation::appointmentForm($values)['fields']['notes']);
    }

    public function test_the_status_form_requires_a_known_status(): void
    {
        $this->assertSame('required', ApptValidation::appointmentStatus(['id' => '', 'status' => 'DONE'])['fields']['id']);
        $this->assertSame('invalidStatus', ApptValidation::appointmentStatus(['id' => 'a', 'status' => 'BOGUS'])['fields']['status']);

        $ok = ApptValidation::appointmentStatus(['id' => 'a', 'status' => 'CANCELLED', 'cancelReason' => '  ill  ']);
        $this->assertSame([], $ok['fields']);
        $this->assertSame('ill', $ok['values']['cancelReason']);
    }

    public function test_the_follow_up_form_requires_a_known_preset(): void
    {
        $this->assertSame('invalidPreset', ApptValidation::followUpForm([
            'patientId' => 'p1', 'preset' => 'bogus',
        ])['fields']['preset']);

        $this->assertSame('required', ApptValidation::followUpForm([
            'patientId' => '', 'preset' => '2w',
        ])['fields']['patientId']);
    }

    public function test_the_custom_follow_up_preset_needs_a_due_date(): void
    {
        $this->assertSame('invalidDate', ApptValidation::followUpForm([
            'patientId' => 'p1', 'preset' => 'custom', 'dueDate' => '',
        ])['fields']['dueDate']);

        $this->assertSame([], ApptValidation::followUpForm([
            'patientId' => 'p1', 'preset' => 'custom', 'dueDate' => '2026-12-01',
        ])['fields']);
    }

    public function test_the_follow_up_status_only_accepts_done_or_cancelled(): void
    {
        $this->assertSame('invalidStatus', ApptValidation::followUpStatus([
            'id' => 'a', 'status' => 'OPEN',
        ])['fields']['status']);

        $this->assertSame([], ApptValidation::followUpStatus(['id' => 'a', 'status' => 'DONE'])['fields']);
    }

    public function test_the_role_predicates(): void
    {
        $this->assertSame(['OWNER', 'RECEPTION'], ApptRoles::APPT_ROLES);
        $this->assertSame(['OWNER', 'RECEPTION'], ApptRoles::FOLLOW_UP_ROLES);
        $this->assertSame(['OWNER'], ApptRoles::APPT_OWNER_ROLES);

        $this->assertFalse(ApptRoles::canManageAppointments('STAFF'));
        $this->assertTrue(ApptRoles::canManageAppointments('OWNER'));
        $this->assertTrue(ApptRoles::canManageAppointments('RECEPTION'));

        $this->assertTrue(ApptRoles::canOverrideTerminalTransition('OWNER'));
        $this->assertFalse(ApptRoles::canOverrideTerminalTransition('RECEPTION'));
        $this->assertFalse(ApptRoles::canOverrideTerminalTransition('STAFF'));
    }

    public function test_the_booking_form_normalises_the_optionals(): void
    {
        $result = BookingValidation::bookingForm([
            'name' => 'Visitor',
            'phone' => '0911223344',
            'preferredDate' => '2026-10-08',
            'preferredTimeWindow' => 'morning',
            'serviceInterest' => '',
            'message' => '',
            'language' => 'am',
            'consent' => true,
        ]);

        $this->assertSame([], $result['fields']);
        $this->assertNull($result['values']['serviceInterest']);
        $this->assertNull($result['values']['message']);
        $this->assertSame('morning', $result['values']['preferredTimeWindow']);
    }

    public function test_the_booking_form_requires_consent(): void
    {
        $result = BookingValidation::bookingForm([
            'name' => 'Visitor',
            'phone' => '0911223344',
            'preferredDate' => '2026-10-08',
            'consent' => false,
        ]);

        $this->assertSame('consentRequired', $result['fields']['consent']);
    }

    public function test_the_booking_form_rejects_a_bad_date_and_an_unknown_window(): void
    {
        $result = BookingValidation::bookingForm([
            'name' => 'Visitor',
            'phone' => '0911223344',
            'preferredDate' => '01-11-2026',
            'preferredTimeWindow' => 'midnight',
            'consent' => true,
        ]);

        $this->assertSame('invalidDate', $result['fields']['preferredDate']);
        $this->assertSame('invalidTimeWindow', $result['fields']['preferredTimeWindow']);
    }

    public function test_the_booking_message_is_capped_at_500_characters(): void
    {
        $values = [
            'name' => 'Visitor',
            'phone' => '0911223344',
            'preferredDate' => '2026-10-08',
            'consent' => true,
        ];

        $tooLong = array_merge($values, ['message' => str_repeat('x', 501)]);
        $this->assertSame('tooLong', BookingValidation::bookingForm($tooLong)['fields']['message']);

        $ok = array_merge($values, ['message' => str_repeat('x', 500)]);
        $this->assertSame([], BookingValidation::bookingForm($ok)['fields']);
    }

    public function test_the_honeypot_is_detected(): void
    {
        $this->assertFalse(BookingValidation::isHoneypotTripped(''));
        $this->assertFalse(BookingValidation::isHoneypotTripped('   '));
        $this->assertTrue(BookingValidation::isHoneypotTripped('http://spam'));
    }

    public function test_the_constants_are_the_legacy_ones(): void
    {
        $this->assertSame(['SCHEDULED', 'CONFIRMED', 'COMPLETED', 'NO_SHOW', 'CANCELLED'], Constants::APPOINTMENT_STATUSES);
        $this->assertSame(['STAFF', 'WEBSITE_REQUEST'], Constants::APPOINTMENT_SOURCES);
        $this->assertSame(['OPEN', 'DONE', 'CANCELLED'], Constants::FOLLOW_UP_STATUSES);
        $this->assertSame([15, 30, 45, 60, 90], Constants::DURATIONS);
        $this->assertSame(30, Constants::DEFAULT_DURATION);
        $this->assertSame(['2w', '1m', '3m', '6m', 'custom'], Constants::FOLLOW_UP_PRESETS);
    }
}
