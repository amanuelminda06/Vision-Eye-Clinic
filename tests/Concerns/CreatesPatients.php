<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Models\Tag;
use App\Models\User;
use App\Support\Patients\TagColors;

/**
 * Builds patient-side fixtures for feature tests. Patients always carry a
 * valid owner (`created_by`), and phone numbers stay unique so the column's
 * unique index never trips by accident.
 */
trait CreatesPatients
{
    protected function createPatient(array $overrides = [], ?User $actor = null): Patient
    {
        $actor ??= $this->createUser(['role' => 'RECEPTION']);

        return Patient::factory()->create(array_merge([
            'created_by' => $actor->id,
        ], $overrides));
    }

    protected function createTag(string $name, ?string $color = null): Tag
    {
        return Tag::create([
            'name' => $name,
            'color' => $color ?? TagColors::for($name),
        ]);
    }

    protected function createSickLeave(Patient $patient, User $actor, array $overrides = []): PatientSickLeave
    {
        return PatientSickLeave::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'recorded_by' => $actor->id,
        ], $overrides));
    }
}
