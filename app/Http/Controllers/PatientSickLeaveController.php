<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Support\Audit;
use App\Support\Patients\PatientValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Patient sick-leave records, ported from
 * src/modules/patients/sick-leave/actions.ts.
 *
 * Sick leave is recorded FOR a patient (issued by the clinic) — non-clinical
 * free text only. Rows are hard-deleted (the module's only hard delete) with a
 * PATIENT_SICK_LEAVE_DELETED audit carrying the full old snapshot.
 */
class PatientSickLeaveController extends Controller
{
    /** POST /patients/{id}/sick-leave — create. */
    public function store(Request $request, string $id): RedirectResponse
    {
        $validated = PatientValidation::sickLeave($this->input($request, $id));

        if ($validated['fields'] !== []) {
            return $this->backToSick($id)->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        if (! Patient::whereKey($values['patientId'])->exists()) {
            return $this->backToSick($id)->withErrors(['patientId' => 'patientRequired']);
        }

        $actor = $request->user();

        $record = PatientSickLeave::create([
            'patient_id' => $values['patientId'],
            'start_date' => $values['startDate'],
            'end_date' => $values['endDate'],
            'reason' => $values['reason'],
            'note' => $values['note'],
            'recorded_by' => $actor->id,
        ]);

        Audit::write('PatientSickLeave', $record->id, 'PATIENT_SICK_LEAVE_CREATED', null, [
            'patientId' => $record->patient_id,
            'startDate' => $record->start_date->format('Y-m-d'),
            'endDate' => $record->end_date->format('Y-m-d'),
            'reason' => $record->reason,
            'note' => $record->note,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return $this->backToSick($id);
    }

    /** POST /patients/{id}/sick-leave/{sickId} — update. */
    public function update(Request $request, string $id, string $sickId): RedirectResponse
    {
        $before = PatientSickLeave::find($sickId);
        abort_if($before === null, 404);

        $validated = PatientValidation::sickLeave($this->input($request, $id));

        if ($validated['fields'] !== []) {
            return $this->backToSick($id)->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        // A record may only be edited from its own patient's page.
        if ($before->patient_id !== $values['patientId']) {
            abort(403, 'This action is not allowed for your role.');
        }

        $old = [
            'startDate' => $before->start_date->format('Y-m-d'),
            'endDate' => $before->end_date->format('Y-m-d'),
            'reason' => $before->reason,
            'note' => $before->note,
        ];

        $before->fill([
            'start_date' => $values['startDate'],
            'end_date' => $values['endDate'],
            'reason' => $values['reason'],
            'note' => $values['note'],
        ])->save();

        $actor = $request->user();
        Audit::write('PatientSickLeave', $sickId, 'PATIENT_SICK_LEAVE_UPDATED', $old, [
            'startDate' => $before->start_date->format('Y-m-d'),
            'endDate' => $before->end_date->format('Y-m-d'),
            'reason' => $before->reason,
            'note' => $before->note,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return $this->backToSick($id);
    }

    /** POST /patients/{id}/sick-leave/{sickId}/delete — hard delete. */
    public function destroy(Request $request, string $id, string $sickId): RedirectResponse
    {
        $before = PatientSickLeave::find($sickId);

        if ($before === null) {
            return $this->backToSick($id);
        }

        $old = [
            'patientId' => $before->patient_id,
            'startDate' => $before->start_date->format('Y-m-d'),
            'endDate' => $before->end_date->format('Y-m-d'),
            'reason' => $before->reason,
            'note' => $before->note,
        ];

        $before->delete();

        $actor = $request->user();
        Audit::write('PatientSickLeave', $sickId, 'PATIENT_SICK_LEAVE_DELETED', $old, null, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return $this->backToSick($id);
    }

    /**
     * Read the sick-leave form fields.
     *
     * @return array<string, string>
     */
    private function input(Request $request, string $id): array
    {
        return [
            'patientId' => (string) $request->input('patientId', $id),
            'startDate' => (string) $request->input('startDate', ''),
            'endDate' => (string) $request->input('endDate', ''),
            'reason' => (string) $request->input('reason', ''),
            'note' => (string) $request->input('note', ''),
        ];
    }

    private function backToSick(string $id): RedirectResponse
    {
        return redirect("/patients/{$id}?tab=sick");
    }
}
