<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 30-day team leave calendar (OWNER + RECEPTION).
 *
 * The window is `from .. from + 29`; the prev/next links step by 30 days. Only
 * REQUESTED and APPROVED leave is listed.
 */
class LeaveCalendarController extends Controller
{
    /** GET /leave/calendar?from=YYYY-MM-DD */
    public function index(Request $request): Response
    {
        $tz = Settings::timezone();

        $fromRaw = (string) $request->query('from', '');
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);
        $fromKey = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1 ? $fromRaw : $todayKey;

        $toKey = ClinicTime::dateKeyAdd($fromKey, 29);

        return response()->view('hr.leave-calendar', [
            'rows' => HrQueries::listTeamLeaveInRange($fromKey, $toKey),
            'fromKey' => $fromKey,
            'toKey' => $toKey,
            'prevKey' => ClinicTime::dateKeyAdd($fromKey, -30),
            'nextKey' => ClinicTime::dateKeyAdd($fromKey, 30),
            'timezone' => $tz,
        ]);
    }
}
