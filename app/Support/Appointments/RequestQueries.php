<?php

declare(strict_types=1);

namespace App\Support\Appointments;

use App\Models\AppointmentRequest;
use App\Support\ClinicTime;
use App\Support\Settings;

/**
 * The website request queue, ported from
 * src/modules/appointments/queries.ts (`listAppointmentRequests`,
 * `getAppointmentRequest`, `countNewRequests`).
 *
 * The requests are read-only projections: the rows carry the visitor's name
 * and phone (both entered by the visitor), never patient clinical data.
 */
final class RequestQueries
{
    /** The legacy cap (200 rows). */
    public const REQUEST_CAP = 200;

    /**
     * The queue, oldest first (the legacy ordering).
     *
     * @return list<array<string, mixed>>
     */
    public static function listRequests(?string $status = null, ?string $timeZone = null): array
    {
        $timeZone ??= Settings::timezone();

        $query = AppointmentRequest::query()
            ->orderBy('created_at')
            ->take(self::REQUEST_CAP);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get()
            ->map(fn (AppointmentRequest $row): array => self::decorate($row, $timeZone))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRequest(string $id, ?string $timeZone = null): ?array
    {
        $timeZone ??= Settings::timezone();

        $row = AppointmentRequest::query()->find($id);

        return $row === null ? null : self::decorate($row, $timeZone);
    }

    /** The unhandled (NEW) requests — the sidebar badge and the dashboard. */
    public static function countNewRequests(): int
    {
        return AppointmentRequest::query()
            ->where('status', AppointmentRequest::STATUS_NEW)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decorate(AppointmentRequest $row, string $timeZone): array
    {
        $createdAt = $row->created_at;

        return [
            'id' => $row->id,
            'name' => (string) $row->name,
            'phoneE164' => (string) $row->phone_e164,
            'preferredDate' => (string) ($row->preferred_date?->format('Y-m-d') ?? ''),
            'preferredTimeWindow' => (string) ($row->preferred_time_window ?? 'any'),
            'serviceInterest' => $row->service_interest,
            'message' => $row->message,
            'language' => (string) $row->language,
            'status' => (string) $row->status,
            'handledBy' => $row->handled_by,
            'handledAt' => $row->handled_at,
            'patientId' => $row->patient_id,
            'appointmentId' => $row->appointment_id,
            'createdAt' => $createdAt,
            'createdAtLabel' => $createdAt === null
                ? ''
                : ClinicTime::clinicDateKey($createdAt, $timeZone).' '
                    .ClinicTime::clinicTimeKey($createdAt, $timeZone),
            'waitingMinutes' => $createdAt === null
                ? 0
                : (int) floor((ClinicTime::nowUtc()->getTimestamp() - $createdAt->getTimestamp()) / 60),
        ];
    }
}
