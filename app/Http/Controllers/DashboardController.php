<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\LeaveRequest;
use App\Models\MessageTask;
use App\Models\Sale;
use App\Support\Appointments\ApptQueries;
use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Hr\Shifts;
use App\Support\Money;
use App\Support\Roles;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard and the daily sales summary (OWNER only).
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();
        $todayKey = ClinicTime::clinicDateKey($now, $tz);

        $isOwner = $actor->role === Roles::OWNER;
        $isReception = $actor->role === Roles::RECEPTION;

        $stats = [];

        if ($isOwner) {
            $todayFrom = ClinicTime::combineDateAndTime($todayKey, '00:00', $tz);
            $todayTo = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($todayKey, 1), '00:00', $tz);

            $weekStart = Shifts::weekStartKey($todayKey);
            $weekTo = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($weekStart, 7), '00:00', $tz);

            $stats['revenueToday'] = Money::toDecimalString(
                Sale::query()
                    ->where('status', Sale::STATUS_CONFIRMED)
                    ->where('sale_date', '>=', $todayFrom)
                    ->where('sale_date', '<', $todayTo)
                    ->sum('total'),
            );

            $stats['revenueWeek'] = Money::toDecimalString(
                Sale::query()
                    ->where('status', Sale::STATUS_CONFIRMED)
                    ->where('sale_date', '>=', ClinicTime::combineDateAndTime($weekStart, '00:00', $tz))
                    ->where('sale_date', '<', $weekTo)
                    ->sum('total'),
            );

            $stats['pendingVoids'] = Sale::query()
                ->where('status', Sale::STATUS_CONFIRMED)
                ->whereNotNull('void_requested_by')
                ->count();

            $stats['pendingLeave'] = LeaveRequest::query()
                ->where('status', LeaveRequest::STATUS_REQUESTED)
                ->count();
        }

        if ($isOwner || $isReception) {
            $stats['todayAppointments'] = ApptQueries::countAppointmentsOnDay($todayKey, $tz);
            $stats['messagesDue'] = MessageTask::query()
                ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
                ->where('due_at', '<', ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($todayKey, 1), '00:00', $tz))
                ->count();
            $stats['overdueFollowUps'] = ApptQueries::countOpenFollowUpsOverdue($todayKey, $tz);
        }

        if ($actor->role === Roles::STAFF) {
            $staffId = HrQueries::getUserStaffId($actor->id);
            $stats['myAppointmentsToday'] = $staffId === null
                ? 0
                : Appointment::query()
                    ->where('doctor_id', $staffId)
                    ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
                    ->where('starts_at', '>=', ClinicTime::combineDateAndTime($todayKey, '00:00', $tz))
                    ->where('starts_at', '<', ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($todayKey, 1), '00:00', $tz))
                    ->count();

            $stats['myLeaveRemaining'] = null;

            if ($staffId !== null) {
                $balance = HrQueries::getLeaveBalance($staffId, (int) substr($todayKey, 0, 4));
                $stats['myLeaveRemaining'] = $balance['remaining'];
            }
        }

        return response()->view('dashboard', [
            'user' => $actor,
            'onDuty' => HrQueries::getOnDutyDoctors($now, $tz),
            'todayKey' => $todayKey,
            'timezone' => $tz,
            'stats' => $stats,
        ]);
    }
}
