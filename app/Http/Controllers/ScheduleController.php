<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Staff;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Hr\HrRoles;
use App\Support\Hr\HrValidation;
use App\Support\Hr\Shifts;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The weekly shift schedule, ported from src/modules/hr/actions.ts and the
 * /schedule page.
 *
 * Rules preserved:
 * - Only the OWNER creates/edits/deletes shifts; RECEPTION and STAFF see the
 *   grid (STAFF sees only their own rows).
 * - A shift may cross midnight (endTime <= startTime).
 * - Overlapping shifts for the SAME staff member are rejected (half-open
 *   intervals).
 * - Delete is idempotent: a missing row is a no-op with no audit row.
 */
class ScheduleController extends Controller
{
    /** GET /schedule?week=YYYY-MM-DD — the weekly grid. */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $weekRaw = (string) $request->query('week', '');
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);
        $weekKey = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRaw) === 1 ? $weekRaw : $todayKey;

        $startKey = Shifts::weekStartKey($weekKey);
        $days = Shifts::weekDays($startKey);

        $from = ClinicTime::combineDateAndTime($days[0], '00:00', $tz);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($days[0], 7), '00:00', $tz);

        // STAFF only ever sees their own rows.
        $staffId = HrRoles::canViewTeamSchedule($actor->role)
            ? null
            : HrQueries::getUserStaffId($actor->id);

        $shifts = HrQueries::listShiftsInRange($from, $to, $staffId);

        return response()->view('hr.schedule', [
            'days' => $days,
            'shifts' => $shifts,
            'startKey' => $startKey,
            'weekKey' => $weekKey,
            'prevKey' => ClinicTime::dateKeyAdd($startKey, -7),
            'nextKey' => ClinicTime::dateKeyAdd($startKey, 7),
            'todayKey' => $todayKey,
            'canManage' => HrRoles::canManageSchedule($actor->role),
            'staffOptions' => HrRoles::canManageSchedule($actor->role) ? HrQueries::listStaffOptions() : collect(),
            'templates' => HrRoles::canManageSchedule($actor->role) ? HrQueries::listTemplates() : [],
            'myStaffId' => HrQueries::getUserStaffId($actor->id),
            'timezone' => $tz,
        ]);
    }

    /** POST /schedule/shifts — create a shift. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $validated = HrValidation::shiftForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $staff = Staff::find($values['staffId']);
        if ($staff === null || ! $staff->active) {
            return back()->with('actionError', 'staffRequired')->withInput();
        }

        $instants = Shifts::shiftInstantsForDay(
            ['startTime' => $values['startTime'], 'endTime' => $values['endTime']],
            $values['dateKey'],
            $tz,
        );

        $clash = $this->conflictingShift($values['staffId'], $instants['startsAt'], $instants['endsAt']);
        if ($clash !== null) {
            return back()->with('actionError', 'shiftOverlap')->withInput();
        }

        $shift = Shift::create([
            'staff_id' => $values['staffId'],
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'source' => 'MANUAL',
            'note' => $values['note'],
            'created_by' => $actor->id,
        ]);

        Audit::write('Shift', $shift->id, 'SHIFT_CREATED', null, [
            'staff' => $staff->full_name,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'source' => 'MANUAL',
            'note' => $values['note'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /schedule/shifts/{id} — update a shift. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $validated = HrValidation::shiftForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $before = Shift::find($id);
        if ($before === null) {
            return back()->with('actionError', 'unknownError');
        }

        $instants = Shifts::shiftInstantsForDay(
            ['startTime' => $values['startTime'], 'endTime' => $values['endTime']],
            $values['dateKey'],
            $tz,
        );

        $clash = $this->conflictingShift($values['staffId'], $instants['startsAt'], $instants['endsAt'], $id);
        if ($clash !== null) {
            return back()->with('actionError', 'shiftOverlap')->withInput();
        }

        $oldStartsAt = $before->starts_at;
        $oldEndsAt = $before->ends_at;

        $before->forceFill([
            'staff_id' => $values['staffId'],
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'note' => $values['note'],
        ])->save();

        Audit::write('Shift', $id, 'SHIFT_UPDATED', [
            'staffId' => $before->getOriginal('staff_id'),
            'startsAt' => $oldStartsAt?->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $oldEndsAt?->format('Y-m-d\TH:i:s.v\Z'),
            'note' => $before->getOriginal('note'),
        ], [
            'staffId' => $before->staff_id,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'note' => $before->note,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /schedule/shifts/{id}/delete — delete a shift (idempotent). */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $shift = Shift::find($id);
        if ($shift === null) {
            // Idempotent: a missing row is a no-op with no audit row.
            return back();
        }

        $snapshot = [
            'staffId' => $shift->staff_id,
            'startsAt' => $shift->starts_at?->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $shift->ends_at?->format('Y-m-d\TH:i:s.v\Z'),
            'source' => (string) $shift->source,
            'note' => $shift->note,
        ];

        $shift->delete();

        Audit::write('Shift', $id, 'SHIFT_DELETED', $snapshot, null, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back();
    }

    /**
     * The first shift of the same staff member that overlaps the candidate.
     *
     * @return array<string, mixed>|null
     */
    private function conflictingShift(string $staffId, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt, ?string $ignoreId = null): ?array
    {
        $neighbours = Shift::query()
            ->where('staff_id', $staffId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'staffId' => $shift->staff_id,
                'startsAt' => $shift->starts_at,
                'endsAt' => $shift->ends_at,
            ])->all();

        return Shifts::findOverlappingShift($neighbours, [
            'staffId' => $staffId,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'ignoreId' => $ignoreId,
        ]);
    }
}
