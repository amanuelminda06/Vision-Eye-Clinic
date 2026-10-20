<?php

namespace App\Providers;

use App\Support\LoginSecret;
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

        // The rule lives in App\Support\LoginSecret: a malformed value is
        // always refused, an empty one only when the app is SERVING in
        // production — the install and the console commands run before the
        // .env exists and must not be blocked by it.
        LoginSecret::assertUsable(
            config('auth.login_secret_path'),
            ! $this->app->runningInConsole(),
            $this->app->environment('production'),
        );
    }
}
