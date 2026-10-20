<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Support\ClinicTime;

/**
 * Public "on duty now" projection, ported from src/modules/hr/public-status.ts.
 *
 * This is the ONLY shape that may leave the clinic for the public website: a
 * strict allow-list of 7 keys. Never phone, email, leave reasons, shift
 * notes, ids of internal records or any other private field.
 */
final class PublicStatus
{
    public const ON_DUTY_NOW = 'ON_DUTY_NOW';

    public const ON_LEAVE = 'ON_LEAVE';

    public const NOT_ON_DUTY = 'NOT_ON_DUTY';

    /**
     * The public subset of a Staff row — only these fields, nothing else.
     *
     * @param  array{id: string, fullName: string, specialty: ?string, photoUrl: ?string, isDoctor: bool, showOnWebsite: bool, active: bool}  $staff
     */
    public static function isPublicDoctor(array $staff): bool
    {
        return $staff['isDoctor'] && $staff['showOnWebsite'] && $staff['active'];
    }

    /**
     * Builds the public status for one doctor. Priority: an approved leave
     * today wins, then a currently-running shift, then not on duty.
     *
     * @param  array{staff: array<string, mixed>, shifts: list<array<string, mixed>>, leaves: list<array<string, mixed>>, now: \DateTimeInterface, timeZone: string}  $input
     * @return array{id: string, name: string, specialty: string|null, photoUrl: string|null, status: string, todayShifts: list<array{start: string, end: string}>, nextShiftStartsAt: string|null}
     */
    public static function buildPublicDoctorStatus(array $input): array
    {
        $staff = $input['staff'];
        $now = $input['now'];
        $timeZone = $input['timeZone'];

        $todayKey = ClinicTime::clinicDateKey($now, $timeZone);

        $ownShifts = array_values(array_filter(
            $input['shifts'],
            fn (array $shift): bool => $shift['staffId'] === $staff['id'],
        ));

        $onLeave = false;
        foreach ($input['leaves'] as $leave) {
            if ($leave['staffId'] === $staff['id']
                && $leave['status'] === 'APPROVED'
                && Leave::leaveCoversDate($leave, $todayKey)) {
                $onLeave = true;
                break;
            }
        }

        $activeShift = null;
        foreach ($ownShifts as $shift) {
            if (Shifts::isShiftActiveAt($shift, $now)) {
                $activeShift = $shift;
                break;
            }
        }

        $status = $onLeave
            ? self::ON_LEAVE
            : ($activeShift !== null ? self::ON_DUTY_NOW : self::NOT_ON_DUTY);

        $dayStart = ClinicTime::startOfDay($now, $timeZone);
        $dayEnd = ClinicTime::endOfDay($now, $timeZone);

        $todayShifts = array_values(array_filter(
            $ownShifts,
            fn (array $shift): bool => $shift['startsAt']->getTimestamp() < $dayEnd->getTimestamp()
                && $shift['endsAt']->getTimestamp() > $dayStart->getTimestamp(),
        ));

        usort($todayShifts, fn (array $a, array $b): int => $a['startsAt']->getTimestamp() <=> $b['startsAt']->getTimestamp());

        $next = $activeShift ?? Shifts::nextShiftAfter($ownShifts, $now);

        return [
            'id' => $staff['id'],
            'name' => $staff['fullName'],
            'specialty' => $staff['specialty'] ?? null,
            'photoUrl' => $staff['photoUrl'] ?? null,
            'status' => $status,
            'todayShifts' => array_map(fn (array $shift): array => [
                'start' => ClinicTime::clinicTimeKey($shift['startsAt'], $timeZone),
                'end' => ClinicTime::clinicTimeKey($shift['endsAt'], $timeZone),
            ], $todayShifts),
            'nextShiftStartsAt' => $next === null
                ? null
                : \DateTimeImmutable::createFromInterface($next['startsAt'])->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * Duty sort rank (ON_DUTY_NOW 0, ON_LEAVE 1, NOT_ON_DUTY 2).
     */
    public static function statusRank(string $status): int
    {
        return match ($status) {
            self::ON_DUTY_NOW => 0,
            self::ON_LEAVE => 1,
            default => 2,
        };
    }
}
