<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PublicAssets;
use Illuminate\Console\Command;

/**
 * Check that public/ can serve the site.
 *
 * Fails (non-zero exit) when:
 *
 * 1. An entry in public/ shadows a registered route (the leftover folders of
 *    the old Next.js app: public/services, public/doctors).
 * 2. A referenced asset is missing from public/.
 * 3. The built assets the Vite manifest promises are missing.
 * 4. favicon.ico is missing or empty.
 *
 *     php artisan public:check
 */
class CheckPublicFolder extends Command
{
    protected $signature = 'public:check';

    protected $description = 'Check that public/ has no route shadowing, no missing assets and a real favicon';

    public function handle(): int
    {
        $problems = [];

        // 1. Route shadowing.
        $collisions = PublicAssets::routeCollisions();

        if ($collisions !== []) {
            $this->error('✗ public/ shadows a Laravel route:');

            foreach ($collisions as $collision) {
                $this->line(sprintf(
                    '    public/%s answers /%s before Laravel runs (a leftover of the old app).',
                    $collision['entry'],
                    $collision['route'],
                ));
            }

            $this->line('    Fix it with: php artisan public:clean-legacy');
            $this->newLine();

            $problems[] = 'route shadowing';
        } else {
            $this->info('✓ no entry in public/ shadows a route');
        }

        // 2. The referenced assets.
        $missing = PublicAssets::missingAssets();

        if ($missing !== []) {
            $this->error('✗ referenced assets are missing:');

            foreach ($missing as $asset) {
                $this->line('    '.$asset);
            }

            $this->newLine();
            $problems[] = 'missing assets';
        } else {
            $this->info('✓ every referenced asset is present');
        }

        // 3. The built assets.
        $missingBuilt = PublicAssets::missingBuiltAssets();

        if ($missingBuilt !== []) {
            $this->error('✗ the built assets are missing:');

            foreach ($missingBuilt as $asset) {
                $this->line('    '.$asset);
            }

            $this->line('    Rebuild them with: npm install && npm run build');
            $this->newLine();

            $problems[] = 'missing built assets';
        } else {
            $this->info('✓ the built assets are in place');
        }

        // 4. The favicon.
        $favicon = PublicAssets::faviconProblem();

        if ($favicon !== null) {
            $this->error('✗ '.$favicon);
            $this->newLine();
            $problems[] = 'favicon';
        } else {
            $this->info('✓ favicon.ico is not empty');
        }

        $this->newLine();

        if ($problems !== []) {
            $this->error(sprintf('• %d problem(s): %s', count($problems), implode(', ', $problems)));

            return self::FAILURE;
        }

        $this->info('• public/ is ready');

        return self::SUCCESS;
    }
}
