<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PublicAssets;
use Illuminate\Console\Command;

/**
 * Remove the leftovers of the old Next.js app from public/.
 *
 * A Plesk Git deployment never DELETES a file that was removed from the
 * repository — it only adds and overwrites. So the folders and files of the
 * legacy app keep sitting in public/ and shadow the Laravel routes
 * (public/services answers /services with a 404 before the framework ever
 * runs).
 *
 * This command removes EXACTLY the known legacy entries and nothing else:
 * every other file in public/ is left alone.
 *
 *     php artisan public:clean-legacy            # remove them
 *     php artisan public:clean-legacy --dry-run   # only report them
 */
class CleanLegacyPublicFiles extends Command
{
    protected $signature = 'public:clean-legacy
                            {--dry-run : Report what would be removed, change nothing}';

    protected $description = 'Remove the known legacy (Next.js) leftovers from public/ — nothing else is touched';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $root = public_path();

        $this->info($dryRun
            ? '• dry run — nothing will be deleted'
            : '• removing the legacy leftovers from public/');

        $removed = 0;
        $skipped = 0;

        foreach (PublicAssets::LEGACY_ENTRIES as $entry) {
            $path = $root.'/'.$entry;

            if (! file_exists($path)) {
                $skipped++;
                $this->line(sprintf('  · %s not present', $entry));

                continue;
            }

            $isDirectory = is_dir($path) && ! is_link($path);

            if ($dryRun) {
                $removed++;
                $this->line(sprintf('  - %s would be removed (%s)', $entry, $isDirectory ? 'directory' : 'file'));

                continue;
            }

            $ok = $isDirectory ? @rmdir($path) : @unlink($path);

            if (! $ok && $isDirectory) {
                // A directory that is not empty: remove its contents too.
                $ok = $this->removeDirectory($path);
            }

            if ($ok) {
                $removed++;
                $this->line(sprintf('  ✓ %s removed', $entry));
            } else {
                $this->error(sprintf('  ✗ %s could NOT be removed — check the permissions', $entry));

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '• %d removed, %d not present%s',
            $removed,
            $skipped,
            $dryRun ? ' (dry run — nothing was deleted)' : '',
        ));

        if (! $dryRun && $removed > 0) {
            $this->line('  Run: php artisan optimize:clear');
        }

        return self::SUCCESS;
    }

    /**
     * Remove a directory and everything inside it.
     */
    private function removeDirectory(string $path): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($path);
    }
}
