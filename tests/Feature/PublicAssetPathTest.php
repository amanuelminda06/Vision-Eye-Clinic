<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A static directory in `public/` shadows every route with the same path: the
 * web server serves the directory instead of the application, so the page
 * 404s (or lists the directory). The legacy build shipped `public/services/`
 * and `public/doctors/` while the public website also serves `/services` and
 * `/doctors` — the route lost.
 *
 * This test fails the build if that ever happens again.
 */
class PublicAssetPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_static_directory_shadows_a_route(): void
    {
        $publicPath = base_path('public');

        $directories = [];
        foreach (scandir($publicPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($publicPath.'/'.$entry)) {
                $directories[] = '/'.$entry;
            }
        }

        $routePaths = [];

        foreach (Route::getRoutes() as $route) {
            $path = '/'.trim($route->uri(), '/');
            $routePaths[$path] = true;
        }

        foreach ($directories as $directory) {
            $this->assertArrayNotHasKey(
                $directory,
                $routePaths,
                "The static directory 'public{$directory}' shadows the route of the same name — move the assets under public/images/.",
            );
        }
    }

    public function test_the_public_asset_directories_live_under_images(): void
    {
        $this->assertDirectoryExists(base_path('public/images/services'));
        $this->assertDirectoryExists(base_path('public/images/doctors'));
    }
}
