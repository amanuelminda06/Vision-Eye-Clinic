<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientSickLeave>
 */
class PatientSickLeaveFactory extends Factory
{
    protected $model = PatientSickLeave::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-30 days', '-1 day');

        return [
            'patient_id' => Patient::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->format('Y-m-d'),
            'reason' => fake()->randomElement(['Fever', 'Post-op rest', 'Flu']),
            'note' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
