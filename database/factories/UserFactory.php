<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * The application's own users table (ULID primary key, argon2id password,
 * role + staff link) — NOT Laravel's default `users` table. The factories
 * that create a patient (and therefore need an owner) use this one.
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => '$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHRzYWx0$c29tZWhhc2hlZGhhc2h2YWx1ZQ',
            'role' => Roles::STAFF,
            'staff_id' => null,
            'active' => true,
            'must_change_password' => false,
            'failed_login_count' => 0,
            'locked_until' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::OWNER]);
    }

    public function reception(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Roles::RECEPTION]);
    }
}
