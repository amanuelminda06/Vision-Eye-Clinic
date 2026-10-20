<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Support\Appointments\Logic;
use App\Support\ClinicTime;

/**
 * Builds appointment/follow-up fixtures for feature tests.
 */
trait CreatesAppointments
{
    protected function createDoctor(array $overrides = []): Staff
    {
        return Staff::factory()->create(array_merge([
            'is_doctor' => true,
            'active' => true,
        ], $overrides));
    }

    protected function createAppointment(array $overrides = []): Appointment
    {
        $tz = 'Africa/Addis_Ababa';
        $dateKey = $overrides['dateKey'] ?? ClinicTime::clinicDateKey(now(), $tz);
        $startTime = $overrides['startTime'] ?? '09:00';
        $durationMin = (int) ($overrides['durationMin'] ?? 30);

        $instants = Logic::appointmentInstants($dateKey, $startTime, $durationMin, $tz);

        unset($overrides['dateKey'], $overrides['startTime'], $overrides['durationMin']);

        if (! isset($overrides['patient_id'])) {
            // The patient factory's own `created_by` uses the Laravel user
            // factory, which does not match this app's users table — so the
            // owner is set explicitly here.
            $overrides['patient_id'] = Patient::factory()->create([
                'created_by' => $this->createUser(['role' => 'OWNER'])->id,
            ])->id;
        }

        if (! isset($overrides['doctor_id'])) {
            $overrides['doctor_id'] = $this->createDoctor()->id;
        }

        if (! isset($overrides['created_by'])) {
            $overrides['created_by'] = $this->createUser(['role' => 'OWNER'])->id;
        }

        return Appointment::create(array_merge([
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'status' => Appointment::STATUS_SCHEDULED,
            'created_from' => Appointment::SOURCE_STAFF,
        ], $overrides));
    }

    protected function createFollowUp(array $overrides = []): FollowUp
    {
        if (! isset($overrides['patient_id'])) {
            $overrides['patient_id'] = Patient::factory()->create([
                'created_by' => $this->createUser(['role' => 'OWNER'])->id,
            ])->id;
        }

        if (! isset($overrides['created_by'])) {
            $overrides['created_by'] = $this->createUser(['role' => 'OWNER'])->id;
        }

        return FollowUp::create(array_merge([
            'due_date' => now()->format('Y-m-d'),
            'status' => FollowUp::STATUS_OPEN,
        ], $overrides));
    }
}
