<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\Hr\HrValidation;
use App\Support\Hr\Shifts;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weekly shift templates: create, delete, preview, apply and "copy last week",
 * ported from src/modules/hr/actions.ts.
 *
 * Rules preserved:
 * - OWNER only.
 * - Preview never writes; apply writes the same rows the preview showed.
 * - Overlapping shifts are SKIPPED, never overwritten.
 * - `SHIFTS_GENERATED` / `SHIFTS_COPIED` are audited only when rows were created.
 */
class ShiftTemplateController extends Controller
{
    /** POST /schedule/templates — create a template. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = HrValidation::templateForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $staff = Staff::find($values['staffId']);
        if ($staff === null) {
            return back()->with('actionError', 'staffRequired')->withInput();
        }

        $template = ShiftTemplate::create([
            'staff_id' => $values['staffId'],
            'weekday' => $values['weekday'],
            'start_time' => $values['startTime'],
            'end_time' => $values['endTime'],
            'valid_from' => $values['validFrom'],
            'valid_to' => $values['validTo'],
        ]);

        Audit::write('ShiftTemplate', $template->id, 'SHIFT_TEMPLATE_CREATED', null, [
            'staff' => $staff->full_name,
            'weekday' => $values['weekday'],
            'startTime' => $values['startTime'],
            'endTime' => $values['endTime'],
            'validFrom' => $values['validFrom'],
            'validTo' => $values['validTo'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /schedule/templates/{id}/delete — delete a template (idempotent). */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $template = ShiftTemplate::find($id);
        if ($template === null) {
            return back();
        }

        $snapshot = [
            'staffId' => $template->staff_id,
            'weekday' => (int) $template->weekday,
            'startTime' => (string) $template->start_time,
            'endTime' => (string) $template->end_time,
        ];

        $template->delete();

        Audit::write('ShiftTemplate', $id, 'SHIFT_TEMPLATE_DELETED', $snapshot, null, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back();
    }

    /** POST /schedule/templates/preview — dry run, no writes. */
    public function preview(Request $request): Response
    {
        $validated = HrValidation::generateRange($request->all());

        if ($validated['fields'] !== []) {
            return response()->view('hr.partials.template-preview', [
                'preview' => null,
                'errors' => $validated['fields'],
            ], 422);
        }

        $values = $validated['values'];
        $tz = Settings::timezone();

        $preview = $this->buildPreview($values['fromKey'], $values['toKey'], $values['staffId'], $tz);

        return response()->view('hr.partials.template-preview', [
            'preview' => $preview,
            'errors' => [],
        ]);
    }

    /** POST /schedule/templates/apply — write the generated shifts. */
    public function apply(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = HrValidation::generateRange($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];
        $tz = Settings::timezone();

        $generated = $this->generate($values['fromKey'], $values['toKey'], $values['staffId'], $tz);
        [$accepted, $skipped] = $this->splitByOverlap($generated, $values['fromKey'], $values['toKey'], $tz);

        if ($accepted !== []) {
            foreach ($accepted as $shift) {
                Shift::create([
                    'staff_id' => $shift['staffId'],
                    'starts_at' => $shift['startsAt'],
                    'ends_at' => $shift['endsAt'],
                    'source' => 'TEMPLATE',
                    'note' => null,
                    'created_by' => $actor->id,
                ]);
            }

            Audit::write('Shift', "template:{$values['fromKey']}:{$values['toKey']}", 'SHIFTS_GENERATED', null, [
                'from' => $values['fromKey'],
                'to' => $values['toKey'],
                'staffId' => $values['staffId'],
                'created' => count($accepted),
                'skipped' => $skipped,
            ], ['id' => $actor->id, 'name' => $actor->name]);
        }

        return back()->with('templateResult', [
            'count' => count($accepted),
            'skipped' => $skipped,
        ]);
    }

    /** POST /schedule/templates/copy-last-week — copy the previous week. */
    public function copyLastWeek(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $tz = Settings::timezone();

        $weekParam = (string) $request->input('weekStart', '');
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);
        $baseKey = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam) === 1 ? $weekParam : $todayKey;

        $targetStart = Shifts::weekStartKey($baseKey);
        $sourceStart = ClinicTime::dateKeyAdd($targetStart, -7);

        $sourceFrom = ClinicTime::combineDateAndTime($sourceStart, '00:00', $tz);
        $sourceTo = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($sourceStart, 7), '00:00', $tz);

        $sourceShifts = Shift::query()
            ->where('starts_at', '<', $sourceTo)
            ->where('ends_at', '>', $sourceFrom)
            ->get()
            ->map(fn (Shift $shift): array => [
                'staffId' => $shift->staff_id,
                'startsAt' => $shift->starts_at,
                'endsAt' => $shift->ends_at,
                'note' => $shift->note,
            ])->all();

        $shifted = array_map(fn (array $shift): array => Shifts::shiftByDays($shift, 7), $sourceShifts);

        [$accepted, $skipped] = $this->splitByOverlap(
            array_map(fn (array $shift): array => [
                'templateId' => '',
                'staffId' => $shift['staffId'],
                'dateKey' => '',
                'startsAt' => $shift['startsAt'],
                'endsAt' => $shift['endsAt'],
                'note' => $shift['note'],
            ], $shifted),
            $targetStart,
            ClinicTime::dateKeyAdd($targetStart, 7),
            $tz,
        );

        if ($accepted !== []) {
            foreach ($accepted as $shift) {
                Shift::create([
                    'staff_id' => $shift['staffId'],
                    'starts_at' => $shift['startsAt'],
                    'ends_at' => $shift['endsAt'],
                    'source' => 'MANUAL',
                    'note' => $shift['note'] ?? null,
                    'created_by' => $actor->id,
                ]);
            }

            Audit::write('Shift', "copy:{$sourceStart}:{$targetStart}", 'SHIFTS_COPIED', null, [
                'from' => $sourceStart,
                'to' => $targetStart,
                'created' => count($accepted),
                'skipped' => $skipped,
            ], ['id' => $actor->id, 'name' => $actor->name]);
        }

        return back()->with('templateResult', [
            'count' => count($accepted),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Expands the templates over the range (pure generation + the DB read).
     *
     * @return list<array{templateId: string, staffId: string, dateKey: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    private function generate(string $fromKey, string $toKey, ?string $staffId, string $tz): array
    {
        $templates = HrQueries::listTemplates($staffId);

        return Shifts::generateTemplateShifts(array_map(fn (array $row): array => [
            'id' => $row['id'],
            'staffId' => $row['staffId'],
            'weekday' => $row['weekday'],
            'startTime' => $row['startTime'],
            'endTime' => $row['endTime'],
            'validFrom' => $row['validFrom'],
            'validTo' => $row['validTo'],
        ], $templates), $fromKey, $toKey, $tz);
    }

    /**
     * Splits the generated shifts into the ones that can be written and the
     * ones that clash with an existing shift of the same staff member.
     *
     * @param  list<array<string, mixed>>  $generated
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function splitByOverlap(array $generated, string $fromKey, string $toKey, string $tz): array
    {
        $rangeFrom = ClinicTime::combineDateAndTime($fromKey, '00:00', $tz);
        $rangeTo = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($toKey, 1), '00:00', $tz);

        $existing = Shift::query()
            ->where('starts_at', '<', $rangeTo)
            ->where('ends_at', '>', $rangeFrom)
            ->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'staffId' => $shift->staff_id,
                'startsAt' => $shift->starts_at,
                'endsAt' => $shift->ends_at,
            ])->all();

        $accepted = [];
        $skipped = 0;

        foreach ($generated as $shift) {
            $clash = Shifts::findOverlappingShift($existing, [
                'staffId' => $shift['staffId'],
                'startsAt' => $shift['startsAt'],
                'endsAt' => $shift['endsAt'],
            ]);

            if ($clash !== null) {
                $skipped++;

                continue;
            }

            $accepted[] = $shift;
        }

        return [$accepted, $skipped];
    }

    /**
     * The preview payload (count, skipped, staff count and a 12-row sample).
     *
     * @return array{count: int, skipped: int, staffCount: int, sample: list<array{staffName: string, dateKey: string, start: string, end: string}>}
     */
    private function buildPreview(string $fromKey, string $toKey, ?string $staffId, string $tz): array
    {
        $generated = $this->generate($fromKey, $toKey, $staffId, $tz);
        [$accepted, $skipped] = $this->splitByOverlap($generated, $fromKey, $toKey, $tz);

        $names = collect(HrQueries::listStaffOptions())->pluck('full_name', 'id');

        $sample = array_map(fn (array $shift): array => [
            'staffName' => $names->get($shift['staffId']) ?? '—',
            'dateKey' => $shift['dateKey'],
            'start' => $shift['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'end' => $shift['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
        ], array_slice($accepted, 0, 12));

        return [
            'count' => count($accepted),
            'skipped' => $skipped,
            'staffCount' => count(array_unique(array_map(fn (array $s): string => $s['staffId'], $generated))),
            'sample' => $sample,
        ];
    }
}
