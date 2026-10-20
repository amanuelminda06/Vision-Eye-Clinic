<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\Hr\Shifts;

/**
 * Builds HR-side fixtures for feature tests: staff members, shifts, weekly
 * templates and leave requests.
 */
trait CreatesHr
{
    protected function createStaff(array $overrides = []): Staff
    {
        return Staff::factory()->create($overrides);
    }

    protected function createShift(array $overrides = []): Shift
    {
        $tz = 'Africa/Addis_Ababa';
        $dateKey = $overrides['dateKey'] ?? ClinicTime::clinicDateKey(now(), $tz);
        $startTime = $overrides['startTime'] ?? '09:00';
        $endTime = $overrides['endTime'] ?? '17:00';

        $instants = Shifts::shiftInstantsForDay(
            ['startTime' => $startTime, 'endTime' => $endTime],
            $dateKey,
            $tz,
        );

        unset($overrides['dateKey'], $overrides['startTime'], $overrides['endTime']);

        return Shift::create(array_merge([
            'staff_id' => $overrides['staff_id'] ?? $this->createStaff()->id,
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'source' => 'MANUAL',
            'note' => null,
            'created_by' => $this->createUser(['role' => 'OWNER'])->id,
        ], $overrides));
    }

    protected function createTemplate(array $overrides = []): ShiftTemplate
    {
        return ShiftTemplate::create(array_merge([
            'staff_id' => $this->createStaff()->id,
            'weekday' => 4,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => '2026-10-01',
            'valid_to' => null,
        ], $overrides));
    }

    protected function createLeaveRequest(array $overrides = [], ?User $actor = null): LeaveRequest
    {
        $actor ??= $this->createUser(['role' => 'RECEPTION']);

        return LeaveRequest::create(array_merge([
            'staff_id' => $this->createStaff()->id,
            'type' => 'ANNUAL',
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-08',
            'half_day' => false,
            'reason' => null,
            'status' => LeaveRequest::STATUS_REQUESTED,
            'created_by' => $actor->id,
        ], $overrides));
    }
}
