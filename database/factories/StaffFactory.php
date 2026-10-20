<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'job_title' => fake()->randomElement(['Ophthalmologist', 'Optometrist', 'Receptionist', 'Nurse']),
            'is_doctor' => fake()->boolean(),
            'specialty' => fake()->randomElement(['Ophthalmology', 'Optometry', 'Cornea', 'Retina']),
            'phone' => null,
            'photo_url' => null,
            'show_on_website' => false,
            'active' => true,
            'annual_leave_days' => fake()->randomElement([14, 20, 26, 30]),
        ];
    }

    /** A doctor published on the public website. */
    public function doctor(bool $published = true): static
    {
        return $this->state(fn (array $attributes) => [
            'is_doctor' => true,
            'show_on_website' => $published,
        ]);
    }

    /** A deactivated staff member. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
