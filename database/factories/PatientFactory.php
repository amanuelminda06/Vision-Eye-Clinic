<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            // Always a valid, unique E.164 Ethiopian mobile — the column is
            // unique, so tests must never collide by accident.
            'phone_e164' => Phone::toE164('09'.fake()->unique()->numerify('########')),
            'sex' => fake()->randomElement(['MALE', 'FEMALE', 'OTHER']),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-1 year')->format('Y-m-d'),
            'preferred_language' => 'en',
            'notes_non_clinical' => null,
            'do_not_message' => false,
            'do_not_message_reason' => null,
            'id_photo_url' => null,
            'id_no' => null,
            'active' => true,
            'created_by' => User::factory(),
        ];
    }

    /** A deactivated patient (the only "delete" the app has). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /** A patient who asked to stop receiving clinic messages. */
    public function doNotMessage(string $reason = 'Patient asked'): static
    {
        return $this->state(fn (array $attributes) => [
            'do_not_message' => true,
            'do_not_message_reason' => $reason,
        ]);
    }
}
