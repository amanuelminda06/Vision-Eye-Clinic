<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard (slice 1 stub — the remaining widgets arrive in slice 8).
 * Gate: any authenticated session, all three roles; forced password change
 * is enforced by the password.changed middleware before this runs.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $now = ClinicTime::nowUtc();
        $tz = Settings::timezone();

        return response()->view('dashboard', [
            'user' => $request->user(),
            'onDuty' => HrQueries::getOnDutyDoctors($now, $tz),
            'todayKey' => ClinicTime::clinicDateKey($now, $tz),
            'timezone' => $tz,
        ]);
    }
}
