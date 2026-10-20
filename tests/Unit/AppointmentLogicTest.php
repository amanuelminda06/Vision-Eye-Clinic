<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Appointments\Logic;
use App\Support\ClinicTime;
use PHPUnit\Framework\TestCase;

/**
 * Appointment geometry, ported from src/modules/appointments/logic.test.ts.
 */
class AppointmentLogicTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    private function at(string $dateKey, string $time): \DateTimeImmutable
    {
        return ClinicTime::combineDateAndTime($dateKey, $time, self::TZ);
    }

    public function test_it_moves_forward_along_the_status_graph(): void
    {
        $this->assertTrue(Logic::transitionAllowed('SCHEDULED', 'CONFIRMED'));
        $this->assertTrue(Logic::transitionAllowed('CONFIRMED', 'COMPLETED'));
        $this->assertFalse(Logic::transitionAllowed('SCHEDULED', 'COMPLETED'));
        $this->assertFalse(Logic::transitionAllowed('CONFIRMED', 'SCHEDULED'));
    }

    public function test_it_lets_scheduled_and_confirmed_cancel_or_no_show(): void
    {
        $this->assertTrue(Logic::transitionAllowed('SCHEDULED', 'CANCELLED'));
        $this->assertTrue(Logic::transitionAllowed('SCHEDULED', 'NO_SHOW'));
        $this->assertTrue(Logic::transitionAllowed('CONFIRMED', 'CANCELLED'));
        $this->assertTrue(Logic::transitionAllowed('CONFIRMED', 'NO_SHOW'));
    }

    public function test_it_rejects_self_transitions_and_unknown_statuses(): void
    {
        $this->assertFalse(Logic::transitionAllowed('CONFIRMED', 'CONFIRMED'));
        $this->assertFalse(Logic::transitionAllowed('NOPE', 'CONFIRMED'));
        $this->assertFalse(Logic::transitionAllowed('CONFIRMED', 'NOPE'));
    }

    public function test_terminal_states_are_frozen_unless_the_owner_overrides(): void
    {
        foreach (['COMPLETED', 'NO_SHOW', 'CANCELLED'] as $terminal) {
            $this->assertFalse(Logic::transitionAllowed($terminal, 'CONFIRMED'));
            $this->assertFalse(Logic::transitionAllowed($terminal, 'SCHEDULED'));
            $this->assertTrue(Logic::transitionAllowed($terminal, 'CONFIRMED', ['ownerOverride' => true]));
            $this->assertTrue(Logic::transitionAllowed($terminal, 'SCHEDULED', ['ownerOverride' => true]));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingAppointments(): array
    {
        return [
            [
                'id' => 'a1', 'doctorId' => 'doc-1', 'status' => 'CONFIRMED',
                'startsAt' => $this->at('2026-10-08', '09:00'), 'endsAt' => $this->at('2026-10-08', '09:45'),
            ],
            [
                'id' => 'a2', 'doctorId' => 'doc-1', 'status' => 'CANCELLED',
                'startsAt' => $this->at('2026-10-08', '10:00'), 'endsAt' => $this->at('2026-10-08', '10:45'),
            ],
            [
                'id' => 'a3', 'doctorId' => 'doc-2', 'status' => 'SCHEDULED',
                'startsAt' => $this->at('2026-10-08', '09:00'), 'endsAt' => $this->at('2026-10-08', '09:45'),
            ],
        ];
    }

    public function test_it_flags_overlapping_active_appointments_for_the_same_doctor(): void
    {
        $clashes = Logic::findDoctorDoubleBookings($this->existingAppointments(), [
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:15'),
            'endsAt' => $this->at('2026-10-08', '10:30'),
        ]);

        $this->assertSame(['a1'], array_column($clashes, 'id'));
    }

    public function test_it_ignores_cancelled_other_doctors_and_the_record_itself(): void
    {
        $clashes = Logic::findDoctorDoubleBookings($this->existingAppointments(), [
            'id' => 'a1',
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:00'),
            'endsAt' => $this->at('2026-10-08', '09:45'),
        ]);

        $this->assertSame([], $clashes);
    }

    public function test_it_treats_adjacent_windows_as_non_overlapping(): void
    {
        $clashes = Logic::findDoctorDoubleBookings($this->existingAppointments(), [
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:45'),
            'endsAt' => $this->at('2026-10-08', '10:45'),
        ]);

        $this->assertSame([], $clashes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shifts(): array
    {
        return [
            [
                'id' => 's1', 'staffId' => 'doc-1',
                'startsAt' => $this->at('2026-10-08', '08:00'), 'endsAt' => $this->at('2026-10-08', '17:00'),
            ],
            [
                'id' => 's2', 'staffId' => 'doc-1',
                'startsAt' => $this->at('2026-10-08', '20:00'), 'endsAt' => $this->at('2026-10-09', '04:00'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leaves(): array
    {
        return [[
            'id' => 'l1', 'staffId' => 'doc-1', 'type' => 'ANNUAL', 'status' => 'APPROVED',
            'startDate' => '2026-10-09', 'endDate' => '2026-10-09', 'halfDay' => false,
        ]];
    }

    public function test_there_are_no_warnings_inside_a_shift_and_outside_leave(): void
    {
        $warnings = Logic::appointmentCoverageWarnings([
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:30'),
            'endsAt' => $this->at('2026-10-08', '10:00'),
        ], $this->shifts(), $this->leaves(), self::TZ);

        $this->assertSame([], $warnings);
    }

    public function test_it_warns_when_the_window_is_outside_any_shift(): void
    {
        $warnings = Logic::appointmentCoverageWarnings([
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '07:00'),
            'endsAt' => $this->at('2026-10-08', '07:30'),
        ], $this->shifts(), $this->leaves(), self::TZ);

        $this->assertContains('outsideShift', $warnings);
    }

    public function test_it_warns_during_approved_leave_even_with_a_shift(): void
    {
        $warnings = Logic::appointmentCoverageWarnings([
            'doctorId' => 'doc-1',
            'startsAt' => $this->at('2026-10-09', '09:00'),
            'endsAt' => $this->at('2026-10-09', '09:30'),
        ], $this->shifts(), $this->leaves(), self::TZ);

        $this->assertContains('onLeave', $warnings);
    }

    public function test_it_handles_an_overnight_shift_as_a_covering_window(): void
    {
        $this->assertTrue(Logic::isInsideShift(
            $this->shifts(),
            $this->at('2026-10-08', '22:00'),
            $this->at('2026-10-08', '23:30'),
        ));
    }

    public function test_it_matches_an_existing_patient_by_exact_e164(): void
    {
        $patients = [
            ['id' => 'p1', 'phoneE164' => '+251911223344'],
            ['id' => 'p2', 'phoneE164' => '+251922334455'],
        ];

        $this->assertSame('p1', Logic::resolvePatientMatch($patients, '+251911223344')['id']);
        $this->assertNull(Logic::resolvePatientMatch($patients, '+251999999999'));
    }

    public function test_it_maps_time_windows_to_wall_clock_slots(): void
    {
        $this->assertSame(['start' => '09:00', 'end' => '10:00'], Logic::slotForTimeWindow('morning'));
        $this->assertSame(['start' => '14:00', 'end' => '15:00'], Logic::slotForTimeWindow('AFTERNOON'));
        $this->assertSame(['start' => '16:00', 'end' => '17:00'], Logic::slotForTimeWindow('evening'));
        $this->assertSame(['start' => '09:00', 'end' => '10:00'], Logic::slotForTimeWindow(null));
        $this->assertSame(['start' => '09:00', 'end' => '10:00'], Logic::slotForTimeWindow('unknown'));
    }

    public function test_it_builds_utc_instants_from_a_clinic_local_day_and_time(): void
    {
        $instants = Logic::appointmentInstants('2026-10-08', '09:00', 30, self::TZ);

        $this->assertSame('2026-10-08T06:00:00.000Z', $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'));
        $this->assertSame('2026-10-08T06:30:00.000Z', $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_the_appointment_round_trips_across_the_overnight_edge(): void
    {
        $overnight = Logic::appointmentInstants('2026-10-08', '23:00', 90, self::TZ);

        $this->assertSame('2026-10-08T21:30:00.000Z', $overnight['endsAt']->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_it_computes_follow_up_due_presets(): void
    {
        $this->assertSame(ClinicTime::dateKeyAdd('2026-10-08', 14), Logic::followUpDueKey('2026-10-08', '2w'));
        $this->assertSame('2026-02-28', Logic::followUpDueKey('2026-01-31', '1m'));
        $this->assertSame('2027-01-08', Logic::followUpDueKey('2026-10-08', '3m'));
        $this->assertSame('2027-04-08', Logic::followUpDueKey('2026-10-08', '6m'));
        $this->assertSame('2026-12-01', Logic::followUpDueKey('2026-10-08', 'custom', '2026-12-01'));
    }

    /**
     * @return list<array{open: string, close: string, closed: bool}>
     */
    private function workingGrid(): array
    {
        return [
            ['open' => '08:00', 'close' => '12:00', 'closed' => true],   // Sunday
            ['open' => '08:00', 'close' => '17:00', 'closed' => false],  // Monday
            ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            ['open' => '08:00', 'close' => '17:00', 'closed' => false],  // Friday
            ['open' => '08:00', 'close' => '12:00', 'closed' => false],  // Saturday
        ];
    }

    public function test_it_counts_only_open_time_minutes_within_a_day(): void
    {
        $this->assertSame(120, Logic::workingMinutesBetween(
            $this->at('2026-10-05', '08:00'),
            $this->at('2026-10-05', '10:00'),
            $this->workingGrid(),
            self::TZ,
        ));

        $this->assertSame(120, Logic::workingMinutesBetween(
            $this->at('2026-10-05', '16:00'),
            $this->at('2026-10-06', '09:00'),
            $this->workingGrid(),
            self::TZ,
        ));
    }

    public function test_it_skips_closed_days(): void
    {
        // Fri 12:00 → Mon 09:00: Fri 300 + Sat 240 + Mon 60.
        $this->assertSame(600, Logic::workingMinutesBetween(
            $this->at('2026-10-02', '12:00'),
            $this->at('2026-10-05', '09:00'),
            $this->workingGrid(),
            self::TZ,
        ));
    }

    public function test_it_returns_zero_for_empty_or_backwards_windows(): void
    {
        $this->assertSame(0, Logic::workingMinutesBetween(
            $this->at('2026-10-05', '10:00'),
            $this->at('2026-10-05', '08:00'),
            $this->workingGrid(),
            self::TZ,
        ));

        $this->assertSame(0, Logic::workingMinutesBetween(
            $this->at('2026-10-05', '08:00'),
            $this->at('2026-10-05', '08:00'),
            $this->workingGrid(),
            self::TZ,
        ));
    }
}
