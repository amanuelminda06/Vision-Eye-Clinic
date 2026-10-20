<?php

namespace App\Providers;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Validates LOGIN_SECRET_PATH at boot (legacy src/env.ts): min 8 chars,
     * /^[A-Za-z0-9_-]+$/. The value itself is never echoed anywhere.
     */
    public function boot(): void
    {
        // stored_files.data needs MEDIUMBLOB (16 MB) on MySQL for photos, and
        // plain BLOB affinity on SQLite — neither grammar defines the type.
        MySqlGrammar::macro('typeMediumblob', fn (): string => 'mediumblob');
        SQLiteGrammar::macro('typeMediumblob', fn (): string => 'blob');

        $secret = (string) config('auth.login_secret_path');
        $valid = $secret !== ''
            && strlen($secret) >= 8
            && preg_match('/^[A-Za-z0-9_-]+$/', $secret) === 1;

        if (! $valid) {
            // Empty outside production only disables login (every /login path
            // 404s); a malformed value is always a hard error, and an empty
            // value in production is a hard error too.
            if ($secret !== '' || app()->environment('production')) {
                throw new \RuntimeException(
                    'LOGIN_SECRET_PATH must be set to at least 8 characters matching [A-Za-z0-9_-].'
                );
            }
        }
    }
}
