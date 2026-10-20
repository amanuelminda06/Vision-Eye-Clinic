<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 100, 5000);

        return [
            'receipt_number' => '2026-'.fake()->unique()->numerify('######'),
            'patient_id' => null,
            'doctor_id' => null,
            'sale_date' => now(),
            'status' => Sale::STATUS_CONFIRMED,
            'payment_method' => 'CASH',
            'total' => $total,
            'created_by' => User::factory(),
            'confirmed_at' => now(),
        ];
    }

    /** A voided sale (excluded from revenue, reported separately). */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Sale::STATUS_VOIDED,
            'voided_by' => $attributes['created_by'],
            'void_reason' => 'Wrong amount charged',
            'voided_at' => now(),
        ]);
    }

    /** A draft sale (never counts as revenue). */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Sale::STATUS_DRAFT,
            'confirmed_at' => null,
        ]);
    }
}
