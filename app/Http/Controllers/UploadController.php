<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stored blobs (patient ID photos, staff photos) to signed-in users.
 *
 * Legacy served these from `/api/uploads/{key}` behind requireRole; the
 * Laravel route is `/uploads/{key}` and is gated by the same auth middleware.
 * Unknown keys are a real 404 — the storage key is never echoed back.
 */
class UploadController extends Controller
{
    public function show(string $key): Response
    {
        try {
            $data = Storage::get($key);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if ($data === null) {
            abort(404);
        }

        return response($data, 200, [
            'Content-Type' => Storage::detectMime($data),
            'Cache-Control' => 'private, max-age=300',
            'Content-Length' => (string) strlen($data),
        ]);
    }
}
