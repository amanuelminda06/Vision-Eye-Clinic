<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Models\PatientSickLeave;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sick-leave queries, ported from
 * src/modules/patients/sick-leave/queries.ts.
 *
 * Rows are sorted `start_date desc, created_at desc` and carry the recorder's
 * name for display. `recorded_by_name` is attached as a transient attribute
 * (there is a FK on recorded_by, but the legacy shape exposes the name inline).
 */
final class SickLeaveQueries
{
    /**
     * @return Collection<int, PatientSickLeave>
     */
    public static function listForPatient(string $patientId): Collection
    {
        $rows = PatientSickLeave::with('recorder')
            ->where('patient_id', $patientId)
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->get();

        foreach ($rows as $row) {
            $row->setAttribute('recorded_by_name', $row->recorder?->name);
        }

        return $rows;
    }

    public static function find(string $id): ?PatientSickLeave
    {
        $row = PatientSickLeave::with('recorder')->find($id);

        if ($row !== null) {
            $row->setAttribute('recorded_by_name', $row->recorder?->name);
        }

        return $row;
    }

    public static function count(string $patientId): int
    {
        return PatientSickLeave::where('patient_id', $patientId)->count();
    }
}
