<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Models\Appointment;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use App\Models\User;
use App\Support\ClinicTime;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;

/**
 * HR queries, ported from src/modules/hr/queries.ts.
 *
 * Everything is portable SQL so it runs on MySQL 8.0 and SQLite (tests). Date
 * columns are clinic-local calendar days; shift columns are UTC instants.
 */
final class HrQueries
{
    /**
     * Active staff for pickers (doctors first, then by name).
     *
     * @return Collection<int, Staff>
     */
    public static function listStaffOptions(): Collection
    {
        return Staff::query()
            ->where('active', true)
            ->orderByDesc('is_doctor')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'is_doctor', 'specialty']);
    }

    public static function getStaffOption(string $id): ?Staff
    {
        return Staff::query()
            ->where('id', $id)
            ->first(['id', 'full_name', 'is_doctor', 'specialty']);
    }

    /** The staff link of a user account (null for owners/reception). */
    public static function getUserStaffId(string $userId): ?string
    {
        return User::query()->where('id', $userId)->value('staff_id');
    }

    /**
     * Shifts overlapping `[from, to)` for one staff member or the whole team.
     *
     * @return list<array<string, mixed>>
     */
    public static function listShiftsInRange(\DateTimeInterface $from, \DateTimeInterface $to, ?string $staffId = null): array
    {
        $query = Shift::query()
            ->with('staff:id,full_name')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at');

        if ($staffId !== null && $staffId !== '') {
            $query->where('staff_id', $staffId);
        }

        return $query->get()->map(fn (Shift $shift): array => self::serializeShift($shift))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getShift(string $id): ?array
    {
        $shift = Shift::query()->with('staff:id,full_name')->find($id);

        return $shift === null ? null : self::serializeShift($shift);
    }

    /**
     * Shift templates, ordered by staff name, weekday and start time.
     *
     * @return list<array<string, mixed>>
     */
    public static function listTemplates(?string $staffId = null): array
    {
        $query = ShiftTemplate::query()
            ->with('staff:id,full_name')
            ->orderBy('staff_id');

        if ($staffId !== null && $staffId !== '') {
            $query->where('staff_id', $staffId);
        }

        $rows = $query->get()->sortBy([
            fn ($a, $b) => strcmp($a->staff?->full_name ?? '', $b->staff?->full_name ?? ''),
            fn ($a, $b) => ((int) $a->weekday) <=> ((int) $b->weekday),
            fn ($a, $b) => strcmp((string) $a->start_time, (string) $b->start_time),
        ])->values();

        return $rows->map(fn (ShiftTemplate $template): array => [
            'id' => $template->id,
            'staffId' => $template->staff_id,
            'staffName' => $template->staff?->full_name ?? '',
            'weekday' => (int) $template->weekday,
            'startTime' => (string) $template->start_time,
            'endTime' => (string) $template->end_time,
            'validFrom' => $template->valid_from,
            'validTo' => $template->valid_to,
        ])->all();
    }

    /**
     * A staff member's own leave requests (newest first, max 100).
     *
     * @return list<array<string, mixed>>
     */
    public static function listLeaveForStaff(string $staffId): array
    {
        $rows = LeaveRequest::query()
            ->with('staff:id,full_name')
            ->where('staff_id', $staffId)
            ->orderByDesc('created_at')
            ->take(100)
            ->get();

        return self::decorateLeave($rows);
    }

    /**
     * Leave requests with a given status (oldest first, max 100).
     *
     * @return list<array<string, mixed>>
     */
    public static function listLeaveByStatus(string $status): array
    {
        $rows = LeaveRequest::query()
            ->with('staff:id,full_name')
            ->where('status', $status)
            ->orderBy('created_at')
            ->take(100)
            ->get();

        return self::decorateLeave($rows);
    }

    /**
     * Approved (and pending) leave overlapping a calendar window — team view.
     *
     * @return list<array<string, mixed>>
     */
    public static function listTeamLeaveInRange(string $fromKey, string $toKey): array
    {
        $rows = LeaveRequest::query()
            ->with('staff:id,full_name')
            ->whereIn('status', ['REQUESTED', 'APPROVED'])
            ->whereDate('start_date', '<=', $toKey)
            ->whereDate('end_date', '>=', $fromKey)
            ->orderBy('start_date')
            ->take(500)
            ->get();

        return self::decorateLeave($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getLeave(string $id): ?array
    {
        $row = LeaveRequest::query()->with('staff:id,full_name')->find($id);

        if ($row === null) {
            return null;
        }

        return self::decorateLeave(collect([$row]))[0];
    }

    /**
     * Pending requests with a count of clashing appointments.
     *
     * @return list<array<string, mixed>>
     */
    public static function listPendingLeaveRequests(): array
    {
        $rows = self::listLeaveByStatus('REQUESTED');

        return array_map(function (array $row): array {
            $row['appointmentConflicts'] = self::countStaffAppointmentsInPeriod(
                $row['staffId'],
                $row['startDate'],
                $row['endDate'],
            );

            return $row;
        }, $rows);
    }

    public static function countPendingLeave(): int
    {
        return LeaveRequest::query()->where('status', 'REQUESTED')->count();
    }

    /**
     * The annual-leave balance of a staff member.
     *
     * @return array{entitlement: int|null, taken: float, remaining: int|null, annualLeaveDays: int|null}
     */
    public static function getLeaveBalance(string $staffId, int $year): array
    {
        $annualLeaveDays = Staff::query()->where('id', $staffId)->value('annual_leave_days');
        $entitlement = $annualLeaveDays === null ? null : (int) $annualLeaveDays;

        $rows = LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('type', 'ANNUAL')
            ->where('status', 'APPROVED')
            ->get();

        $balance = Leave::leaveBalance($entitlement, $rows->map(fn (LeaveRequest $leave): array => [
            'id' => $leave->id,
            'staffId' => $leave->staff_id,
            'type' => $leave->type,
            'status' => $leave->status,
            'startDate' => $leave->start_date,
            'endDate' => $leave->end_date,
            'halfDay' => (bool) $leave->half_day,
        ])->all(), $year);

        return array_merge($balance, ['annualLeaveDays' => $entitlement]);
    }

    /**
     * Approved leave covering `dateKey`, for a set of staff ids.
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    public static function listApprovedLeaveCovering(array $staffIds, string $dateKey): array
    {
        if ($staffIds === []) {
            return [];
        }

        return LeaveRequest::query()
            ->where('status', 'APPROVED')
            ->whereIn('staff_id', $staffIds)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'staffId' => $leave->staff_id,
                'type' => $leave->type,
                'status' => $leave->status,
                'startDate' => $leave->start_date,
                'endDate' => $leave->end_date,
                'halfDay' => (bool) $leave->half_day,
            ])->all();
    }

    /**
     * Doctors (any) with today's duty status — the dashboard "who is on duty".
     *
     * @return list<array<string, mixed>>
     */
    public static function getOnDutyDoctors(?\DateTimeInterface $now = null, ?string $timeZone = null): array
    {
        return self::getDoctorDutyStatuses($now ?? ClinicTime::nowUtc(), $timeZone, false);
    }

    /**
     * PUBLIC data: only doctors flagged `showOnWebsite`.
     *
     * @return list<array<string, mixed>>
     */
    public static function getPublicDoctorStatus(?\DateTimeInterface $now = null, ?string $timeZone = null): array
    {
        return self::getDoctorDutyStatuses($now ?? ClinicTime::nowUtc(), $timeZone, true);
    }

    /**
     * Count of a doctor's appointments that fall inside a leave period.
     *
     * The window is `[startDate, endDate)` as UTC-midnight instants — the final
     * day of the leave is effectively excluded (legacy quirk, kept verbatim).
     */
    public static function countStaffAppointmentsInPeriod(string $staffId, mixed $startDate, mixed $endDate): int
    {
        $start = self::dateKeyToUtcDate(Shifts::dateColumnToKey($startDate));
        $end = self::dateKeyToUtcDate(Shifts::dateColumnToKey($endDate));

        return Appointment::query()
            ->where('doctor_id', $staffId)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->take(100)
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function getDoctorDutyStatuses(\DateTimeInterface $now, ?string $timeZone, bool $publicOnly): array
    {
        $timeZone ??= Settings::timezone();

        $query = Staff::query()
            ->where('active', true)
            ->where('is_doctor', true)
            ->orderBy('full_name');

        if ($publicOnly) {
            $query->where('show_on_website', true);
        }

        $staff = $query->get(['id', 'full_name', 'specialty', 'photo_url', 'is_doctor', 'show_on_website', 'active']);

        if ($staff->isEmpty()) {
            return [];
        }

        $ids = $staff->pluck('id')->all();
        $dayStart = ClinicTime::startOfDay($now, $timeZone);
        $todayKey = ClinicTime::clinicDateKey($now, $timeZone);

        $shifts = Shift::query()
            ->whereIn('staff_id', $ids)
            ->where('ends_at', '>=', $dayStart)
            ->orderBy('starts_at')
            ->take(1000)
            ->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'staffId' => $shift->staff_id,
                'startsAt' => $shift->starts_at,
                'endsAt' => $shift->ends_at,
            ])->all();

        $leaves = self::listApprovedLeaveCovering($ids, $todayKey);

        $statuses = $staff->map(fn (Staff $member): array => PublicStatus::buildPublicDoctorStatus([
            'staff' => [
                'id' => $member->id,
                'fullName' => $member->full_name,
                'specialty' => $member->specialty,
                'photoUrl' => $member->photo_url,
                'isDoctor' => (bool) $member->is_doctor,
                'showOnWebsite' => (bool) $member->show_on_website,
                'active' => (bool) $member->active,
            ],
            'shifts' => $shifts,
            'leaves' => $leaves,
            'now' => $now,
            'timeZone' => $timeZone,
        ]))->all();

        usort($statuses, function (array $a, array $b): int {
            $rank = PublicStatus::statusRank($a['status']) <=> PublicStatus::statusRank($b['status']);

            return $rank !== 0 ? $rank : strcoll((string) $a['name'], (string) $b['name']);
        });

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeShift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'staffId' => $shift->staff_id,
            'staffName' => $shift->staff?->full_name ?? '',
            'startsAt' => $shift->starts_at,
            'endsAt' => $shift->ends_at,
            'source' => (string) $shift->source,
            'note' => $shift->note,
            'createdBy' => (string) $shift->created_by,
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $rows
     * @return list<array<string, mixed>>
     */
    private static function decorateLeave(Collection $rows): array
    {
        $deciderIds = array_values(array_unique(array_filter(
            $rows->pluck('decided_by')->all(),
            fn ($id): bool => $id !== null && $id !== '',
        )));

        $names = $deciderIds === []
            ? collect()
            : User::query()->whereIn('id', $deciderIds)->pluck('name', 'id');

        return $rows->map(fn (LeaveRequest $row): array => [
            'id' => $row->id,
            'staffId' => $row->staff_id,
            'staffName' => $row->staff?->full_name ?? '',
            'type' => (string) $row->type,
            'startDate' => $row->start_date,
            'endDate' => $row->end_date,
            'halfDay' => (bool) $row->half_day,
            'reason' => $row->reason,
            'status' => (string) $row->status,
            'decidedBy' => $row->decided_by,
            'decidedByName' => $row->decided_by === null ? null : $names->get($row->decided_by),
            'decidedAt' => $row->decided_at,
            'decisionNote' => $row->decision_note,
            'createdBy' => (string) $row->created_by,
            'createdAt' => $row->created_at,
        ])->all();
    }

    /** UTC-midnight instant of a `YYYY-MM-DD` key. */
    private static function dateKeyToUtcDate(string $dateKey): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateKey.'T00:00:00Z');
    }
}
