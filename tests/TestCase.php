<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The base test case.
 *
 * The suite runs on SQLite by default (`php artisan test`). To run it against
 * a real MySQL 8.0 instead, set the DB_* environment variables — see
 * `scripts/mysql-dev.sh` and docs/PLESK_DEPLOYMENT.md.
 */
abstract class TestCase extends BaseTestCase
{
    // ...
}
