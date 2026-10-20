<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+30 days');

        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => Staff::factory()->state(fn (array $attributes) => [
                'is_doctor' => true,
                'active' => true,
            ]),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+30 minutes'),
            'service_id' => null,
            'status' => Appointment::STATUS_SCHEDULED,
            'cancel_reason' => null,
            'notes' => null,
            'created_from' => Appointment::SOURCE_STAFF,
            'created_by' => User::factory(),
        ];
    }

    /** A confirmed appointment. */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Appointment::STATUS_CONFIRMED]);
    }

    /** A terminal (completed) appointment. */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Appointment::STATUS_COMPLETED]);
    }
}
