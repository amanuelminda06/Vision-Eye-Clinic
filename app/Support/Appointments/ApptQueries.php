<?php

declare(strict_types=1);

namespace App\Support\Appointments;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\LeaveRequest;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\Staff;
use App\Support\ClinicTime;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Appointment + follow-up queries, ported from
 * src/modules/appointments/queries.ts.
 *
 * Everything is portable SQL (LIKE with escaped wildcards, enum checks) so it
 * runs on MySQL 8.0 and SQLite (tests). Day boundaries are always clinic
 * timezone instants.
 */
final class ApptQueries
{
    /** Board / export cap (legacy `take: 500`). */
    public const APPOINTMENT_CAP = 500;

    /** Follow-up cap (legacy `take: 300`). */
    public const FOLLOW_UP_CAP = 300;

    /** Patient filter options cap (legacy `take: 300`). */
    public const PATIENT_OPTION_CAP = 300;

    /**
     * @param  array{from?: ?\DateTimeInterface, to?: ?\DateTimeInterface, doctorId?: ?string, patientId?: ?string, status?: ?string}  $params
     * @return list<array<string, mixed>>
     */
    public static function listAppointments(array $params): array
    {
        $query = Appointment::query()
            ->with(['patient:id,full_name,phone_e164', 'doctor:id,full_name', 'service:id,name'])
            ->orderBy('starts_at')
            ->take(self::APPOINTMENT_CAP);

        if (! empty($params['doctorId'])) {
            $query->where('doctor_id', $params['doctorId']);
        }

        if (! empty($params['patientId'])) {
            $query->where('patient_id', $params['patientId']);
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['from'])) {
            $query->where('starts_at', '>=', $params['from']);
        }

        if (isset($params['to'])) {
            $query->where('starts_at', '<', $params['to']);
        }

        return $query->get()->map(fn (Appointment $row): array => self::decorateAppointment($row))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getAppointment(string $id): ?array
    {
        $row = Appointment::query()
            ->with(['patient:id,full_name,phone_e164', 'doctor:id,full_name', 'service:id,name'])
            ->find($id);

        return $row === null ? null : self::decorateAppointment($row);
    }

    /** Active appointments that start on the given clinic-local day. */
    public static function countAppointmentsOnDay(string $dayKey, string $timeZone): int
    {
        $from = ClinicTime::combineDateAndTime($dayKey, '00:00', $timeZone);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dayKey, 1), '00:00', $timeZone);

        return Appointment::query()
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listPatientAppointments(string $patientId): array
    {
        return self::listAppointments(['patientId' => $patientId]);
    }

    public static function countPatientAppointments(string $patientId): int
    {
        return Appointment::query()->where('patient_id', $patientId)->count();
    }

    /**
     * HR seam: active appointments overlapping `[from, to)` for one doctor.
     *
     * @return list<array{id: string, title: string, startsAt: \DateTimeInterface}>
     */
    public static function getStaffAppointmentsInPeriod(string $staffId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return Appointment::query()
            ->with('patient:id,full_name')
            ->where('doctor_id', $staffId)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->take(100)
            ->get()
            ->map(fn (Appointment $row): array => [
                'id' => $row->id,
                'title' => $row->patient?->full_name ?? '',
                'startsAt' => $row->starts_at,
            ])->all();
    }

    public static function countStaffAppointmentsInPeriod(string $staffId, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return count(self::getStaffAppointmentsInPeriod($staffId, $from, $to));
    }

    /**
     * @param  array{status?: ?string, patientId?: ?string, dueBeforeKey?: ?string, fallbackTz?: ?string}  $params
     * @return list<array<string, mixed>>
     */
    public static function listFollowUps(array $params): array
    {
        $timeZone = $params['fallbackTz'] ?? Settings::timezone();

        $query = FollowUp::query()
            ->with('patient:id,full_name,phone_e164')
            ->orderBy('due_date')
            ->take(self::FOLLOW_UP_CAP);

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['patientId'])) {
            $query->where('patient_id', $params['patientId']);
        }

        if (! empty($params['dueBeforeKey'])) {
            $query->where('due_date', '<', ClinicTime::combineDateAndTime($params['dueBeforeKey'], '00:00', $timeZone));
        }

        return $query->get()->map(fn (FollowUp $row): array => self::decorateFollowUp($row))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getFollowUp(string $id): ?array
    {
        $row = FollowUp::query()->with('patient:id,full_name,phone_e164')->find($id);

        return $row === null ? null : self::decorateFollowUp($row);
    }

    /** Open follow-ups due strictly before the given clinic-local day. */
    public static function countOpenFollowUpsOverdue(string $todayKey, string $timeZone): int
    {
        return FollowUp::query()
            ->where('status', FollowUp::STATUS_OPEN)
            ->where('due_date', '<', ClinicTime::combineDateAndTime($todayKey, '00:00', $timeZone))
            ->count();
    }

    /**
     * Shifts of one doctor overlapping `[from, to)` (as pure shift records).
     *
     * @return list<array{id: string, staffId: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}>
     */
    public static function getDoctorShiftsForCoverage(string $doctorId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return Shift::query()
            ->where('staff_id', $doctorId)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->take(200)
            ->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'staffId' => $shift->staff_id,
                'startsAt' => $shift->starts_at,
                'endsAt' => $shift->ends_at,
            ])->all();
    }

    /**
     * Approved leave covering any date in the range for the given staff.
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    public static function getApprovedLeaveInRange(array $staffIds, string $fromKey, string $toKey, string $timeZone): array
    {
        if ($staffIds === []) {
            return [];
        }

        $from = ClinicTime::combineDateAndTime($fromKey, '00:00', $timeZone);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($toKey, 1), '00:00', $timeZone);

        return LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereIn('staff_id', $staffIds)
            ->whereDate('start_date', '<=', ClinicTime::clinicDateKey($to, $timeZone))
            ->whereDate('end_date', '>=', ClinicTime::clinicDateKey($from, $timeZone))
            ->take(500)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'staffId' => $leave->staff_id,
                'type' => (string) $leave->type,
                'status' => (string) $leave->status,
                'startDate' => $leave->start_date,
                'endDate' => $leave->end_date,
                'halfDay' => (bool) $leave->half_day,
            ])->all();
    }

    /**
     * Active doctors for the appointment form.
     *
     * @return Collection<int, Staff>
     */
    public static function listDoctorOptions(): Collection
    {
        return Staff::query()
            ->where('active', true)
            ->where('is_doctor', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'specialty']);
    }

    /**
     * Active patients for the follow-up filter (max 300).
     *
     * @return Collection<int, Patient>
     */
    public static function listPatientOptions(): Collection
    {
        return Patient::query()
            ->where('active', true)
            ->orderBy('full_name')
            ->take(self::PATIENT_OPTION_CAP)
            ->get(['id', 'full_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decorateAppointment(Appointment $row): array
    {
        return [
            'id' => $row->id,
            'patientId' => $row->patient_id,
            'patientName' => $row->patient?->full_name ?? '',
            'patientPhoneE164' => $row->patient?->phone_e164,
            'doctorId' => $row->doctor_id,
            'doctorName' => $row->doctor?->full_name ?? '',
            'serviceId' => $row->service_id,
            'serviceName' => $row->service?->name,
            'startsAt' => $row->starts_at,
            'endsAt' => $row->ends_at,
            'status' => (string) $row->status,
            'createdFrom' => (string) $row->created_from,
            'cancelReason' => $row->cancel_reason,
            'notes' => $row->notes,
            'createdBy' => (string) $row->created_by,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decorateFollowUp(FollowUp $row): array
    {
        return [
            'id' => $row->id,
            'patientId' => $row->patient_id,
            'patientName' => $row->patient?->full_name ?? '',
            'patientPhoneE164' => $row->patient?->phone_e164,
            'dueDate' => $row->due_date,
            'reason' => $row->reason,
            'status' => (string) $row->status,
            'createdFromAppointmentId' => $row->created_from_appointment_id,
            'createdBy' => (string) $row->created_by,
            'createdAt' => $row->created_at,
        ];
    }
}
