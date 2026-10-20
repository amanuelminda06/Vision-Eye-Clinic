<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Support\Patients\TagColors;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => mb_strtolower($name, 'UTF-8'),
            'color' => TagColors::for(mb_strtolower($name, 'UTF-8')),
        ];
    }
}
