<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Public "on duty now" feed, ported from src/app/api/public/doctors/route.ts.
 *
 * This route needs NO session (it lives in the API middleware group) and
 * returns a strict allow-list projection: only the 7 public keys per doctor.
 * Never phone, email, leave reasons, shift notes or internal ids.
 */
class PublicDoctorsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = ClinicTime::nowUtc();
        $tz = Settings::timezone();

        return response()->json([
            'doctors' => HrQueries::getPublicDoctorStatus($now, $tz),
            'generatedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');
    }
}
