<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Storage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stored blobs (patient ID photos, staff photos) to signed-in users.
 *
 * Legacy served these from `/api/uploads/{key}` behind requireRole; the
 * Laravel route is `/uploads/{key}` and is gated by the same auth middleware.
 * Unknown keys are a real 404 — the storage key is never echoed back.
 *
 * Patient ID photos are an opt-in feature: when the setting is off the bytes
 * are refused. Every view of a patient ID photo is audited
 * (`PATIENT_ID_PHOTO_VIEWED`) — the thumbnail, the full-size dialog and the
 * capture preview all go through this route.
 */
class UploadController extends Controller
{
    public function show(Request $request, string $key): Response
    {
        try {
            $data = Storage::get($key);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if ($data === null) {
            abort(404);
        }

        if (Storage::isPatientIdPhotoKey($key)) {
            $this->guardPatientIdPhoto($request, $key);
        }

        return response($data, 200, [
            'Content-Type' => Storage::detectMime($data),
            'Cache-Control' => 'private, max-age=300',
            'Content-Length' => (string) strlen($data),
        ]);
    }

    /**
     * A staff photo for the signed-in ERP.
     *
     * The doctor picture is not patient data — every signed-in role may read
     * it (the dashboard shows the on-duty doctors to a STAFF user too). The
     * route serves the `staff-` keys only; a patient ID photo key never
     * resolves here, it stays behind the OWNER+RECEPTION upload route.
     */
    public function staffPhoto(string $key): Response
    {
        if (! str_starts_with($key, Storage::STAFF_PHOTO_KEY_PREFIX)) {
            abort(404);
        }

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

    /**
     * The feature gate + the view audit for a patient ID photo.
     */
    private function guardPatientIdPhoto(Request $request, string $key): void
    {
        if (! Settings::patientIdPhotosEnabled()) {
            abort(403, 'Patient ID photos are disabled.');
        }

        $patient = Patient::query()
            ->whereIn('id_photo_url', ['/uploads/'.$key, '/api/uploads/'.$key])
            ->first();

        // Every view is audited (the VIEW dedupe does not apply: the action is
        // not the generic 'VIEW').
        Audit::write(
            'Patient',
            $patient?->id ?? 'unknown',
            'PATIENT_ID_PHOTO_VIEWED',
            null,
            ['idPhotoUrl' => $patient?->id_photo_url, 'key' => $key],
            $request->user() === null ? null : ['id' => $request->user()->id, 'name' => $request->user()->name],
        );
    }
}
