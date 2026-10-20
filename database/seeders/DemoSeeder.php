<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DEV/TEST only — demo data (patients, sales, appointments, …).
 * NEVER run on production (`php artisan db:seed` runs DatabaseSeeder only).
 *
 * Slice 2 adds the demo patient rows ported from prisma/seed-patients.ts;
 * later slices add their own demo data here (never to DatabaseSeeder).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('DemoSeeder: no demo rows defined for this slice yet.');
    }
}
