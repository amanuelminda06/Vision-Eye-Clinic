<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds a valid staff account for feature tests. Defaults are login-ready:
 * active, no pending password change, argon2id hash.
 */
trait CreatesUsers
{
    protected function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => Hash::make('CorrectHorseBattery9'),
            'role' => Roles::OWNER,
            'active' => true,
            'must_change_password' => false,
            'failed_login_count' => 0,
            'locked_until' => null,
        ], $overrides));
    }

    /** The secret login URL for the current test env. */
    protected function loginPath(): string
    {
        return '/login/'.(string) config('auth.login_secret_path');
    }
}
