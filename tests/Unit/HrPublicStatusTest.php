<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ClinicTime;
use App\Support\Hr\PublicStatus;
use PHPUnit\Framework\TestCase;

/**
 * Public doctor status, ported from src/modules/hr/public-status.test.ts.
 */
class HrPublicStatusTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    /** @return array<string, mixed> */
    private function doctor(): array
    {
        return [
            'id' => 'doc-1',
            'fullName' => 'Dr. Abebe Kebede',
            'specialty' => 'Ophthalmology',
            'photoUrl' => '/api/uploads/doc-1.png',
            'isDoctor' => true,
            'showOnWebsite' => true,
            'active' => true,
        ];
    }

    private function at(string $dateKey, string $time): \DateTimeImmutable
    {
        return ClinicTime::combineDateAndTime($dateKey, $time, self::TZ);
    }

    public function test_it_publishes_only_flagged_active_doctors(): void
    {
        $this->assertTrue(PublicStatus::isPublicDoctor($this->doctor()));
        $this->assertFalse(PublicStatus::isPublicDoctor(array_merge($this->doctor(), ['active' => false])));
        $this->assertFalse(PublicStatus::isPublicDoctor(array_merge($this->doctor(), ['showOnWebsite' => false])));
        $this->assertFalse(PublicStatus::isPublicDoctor(array_merge($this->doctor(), ['isDoctor' => false])));
    }

    public function test_it_reports_on_duty_now_during_a_running_shift(): void
    {
        $shifts = [[
            'id' => 's1',
            'staffId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:00'),
            'endsAt' => $this->at('2026-10-08', '17:00'),
        ]];

        $status = PublicStatus::buildPublicDoctorStatus([
            'staff' => $this->doctor(),
            'shifts' => $shifts,
            'leaves' => [],
            'now' => $this->at('2026-10-08', '10:00'),
            'timeZone' => self::TZ,
        ]);

        $this->assertSame('ON_DUTY_NOW', $status['status']);
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $status['todayShifts']);
        $this->assertSame(
            $this->at('2026-10-08', '09:00')->format('Y-m-d\TH:i:s.v\Z'),
            $status['nextShiftStartsAt'],
        );
    }

    public function test_approved_leave_wins_even_during_a_shift(): void
    {
        $shifts = [[
            'id' => 's1',
            'staffId' => 'doc-1',
            'startsAt' => $this->at('2026-10-08', '09:00'),
            'endsAt' => $this->at('2026-10-08', '17:00'),
        ]];

        $leaves = [[
            'id' => 'l1',
            'staffId' => 'doc-1',
            'type' => 'ANNUAL',
            'status' => 'APPROVED',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
            'halfDay' => false,
        ]];

        $status = PublicStatus::buildPublicDoctorStatus([
            'staff' => $this->doctor(),
            'shifts' => $shifts,
            'leaves' => $leaves,
            'now' => $this->at('2026-10-08', '10:00'),
            'timeZone' => self::TZ,
        ]);

        $this->assertSame('ON_LEAVE', $status['status']);
    }

    public function test_it_reports_not_on_duty_with_the_next_shift(): void
    {
        $shifts = [[
            'id' => 's1',
            'staffId' => 'doc-1',
            'startsAt' => $this->at('2026-10-09', '09:00'),
            'endsAt' => $this->at('2026-10-09', '17:00'),
        ]];

        $status = PublicStatus::buildPublicDoctorStatus([
            'staff' => $this->doctor(),
            'shifts' => $shifts,
            'leaves' => [],
            'now' => $this->at('2026-10-08', '10:00'),
            'timeZone' => self::TZ,
        ]);

        $this->assertSame('NOT_ON_DUTY', $status['status']);
        $this->assertSame([], $status['todayShifts']);
        $this->assertSame(
            $this->at('2026-10-09', '09:00')->format('Y-m-d\TH:i:s.v\Z'),
            $status['nextShiftStartsAt'],
        );
    }

    public function test_it_never_leaks_private_or_internal_fields(): void
    {
        $raw = array_merge($this->doctor(), [
            'phone' => '+251911223344',
            'email' => 'doctor@visioneye.et',
            'annualLeaveDays' => 20,
            'jobTitle' => 'Ophthalmologist',
            'secret' => 'internal',
        ]);

        $leaves = [[
            'id' => 'l1',
            'staffId' => 'doc-1',
            'type' => 'SICK',
            'status' => 'APPROVED',
            'startDate' => '2026-10-08',
            'endDate' => '2026-10-08',
            'halfDay' => false,
        ]];

        $status = PublicStatus::buildPublicDoctorStatus([
            'staff' => $raw,
            'shifts' => [],
            'leaves' => $leaves,
            'now' => $this->at('2026-10-08', '10:00'),
            'timeZone' => self::TZ,
        ]);

        $json = json_encode($status);

        foreach ([
            'phone', '+251911223344', 'email', 'doctor@visioneye.et',
            'annualLeaveDays', 'jobTitle', 'secret', 'internal',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $json);
        }

        $keys = array_keys($status);
        sort($keys);

        $this->assertSame([
            'id', 'name', 'nextShiftStartsAt', 'photoUrl', 'specialty', 'status', 'todayShifts',
        ], $keys);
    }
}
