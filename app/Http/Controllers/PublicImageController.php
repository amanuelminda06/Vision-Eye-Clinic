<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Staff;
use App\Support\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two narrow public image routes.
 *
 * Uploaded blobs are NEVER exposed directly — only through these routes,
 * gated by the publication flags. Static site-relative paths render as-is.
 */
class PublicImageController extends Controller
{
    /** GET /api/public/doctors/{id}/photo */
    public function doctorPhoto(string $id): Response
    {
        $staff = Staff::query()
            ->where('id', $id)
            ->where('is_doctor', true)
            ->where('show_on_website', true)
            ->where('active', true)
            ->first(['photo_url']);

        if ($staff === null || $staff->photo_url === null) {
            abort(404);
        }

        return $this->serve($staff->photo_url);
    }

    /** GET /api/public/services/{id}/image */
    public function serviceImage(string $id): Response
    {
        $service = Service::query()
            ->where('id', $id)
            ->where('is_public', true)
            ->where('active', true)
            ->first(['image_url']);

        if ($service === null || $service->image_url === null) {
            abort(404);
        }

        return $this->serve($service->image_url);
    }

    /**
     * Serves an uploaded blob through the public route.
     */
    private function serve(string $imageUrl): Response
    {
        if (! str_starts_with($imageUrl, '/api/uploads/')) {
            abort(404);
        }

        $key = substr($imageUrl, strlen('/api/uploads/'));
        $bytes = Storage::get($key);

        if ($bytes === null) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => self::contentType($key),
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
        ]);
    }

    /** The content type by extension (default `application/octet-stream`). */
    private static function contentType(string $key): string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
