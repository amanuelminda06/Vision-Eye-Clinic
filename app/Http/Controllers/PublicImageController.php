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

        // A doctor's photo_url must never be a patient ID photo key — those
        // keys are patient data and are only served through the authenticated
        // upload route. If someone manually corrupts the column, we 404.
        $key = Storage::keyFromUrl($staff->photo_url);
        if ($key !== null && Storage::isPatientIdPhotoKey($key)) {
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
     *
     * Both URL prefixes count — the Laravel route writes `/uploads/{key}`,
     * the legacy rows still carry `/api/uploads/{key}`.
     */
    private function serve(string $imageUrl): Response
    {
        $key = Storage::keyFromUrl($imageUrl);

        if ($key === null) {
            abort(404);
        }

        try {
            $bytes = Storage::get($key);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if ($bytes === null) {
            abort(404);
        }

        $detected = Storage::detectMime($bytes);

        return response($bytes, 200, [
            'Content-Type' => $detected === 'application/octet-stream' ? self::contentType($key) : $detected,
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
