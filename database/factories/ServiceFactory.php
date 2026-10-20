<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Eye Consultation',
            'OCT Scan',
            'Glasses Fitting',
            'Contact Lens Check',
            'Retina Examination',
            'Cataract Assessment',
        ]);

        return [
            'name' => $name,
            'name_en' => $name,
            'name_am' => null,
            'category' => 'consultation',
            'current_price' => fake()->randomFloat(2, 100, 5000),
            'active' => true,
            'is_public' => true,
            'public_description_en' => null,
            'public_description_am' => null,
            'receipt_label_en' => null,
            'receipt_label_am' => null,
            'display_order' => 0,
            'image_url' => null,
        ];
    }

    /** A deactivated service (cannot be added to a new sale). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
