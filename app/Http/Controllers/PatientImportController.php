<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Tag;
use App\Support\Audit;
use App\Support\Patients\PatientImport;
use App\Support\Patients\SystemId;
use App\Support\Patients\TagColors;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV patient import wizard (preview → confirm), ported from
 * importPatientsAction in src/modules/patients/actions.ts.
 *
 * Server-side rules preserved:
 * - file > 2 MiB → fileTooLarge (both steps; confirm re-checks echoed length).
 * - > 1000 data rows → tooManyRows (whole file rejected).
 * - missing required columns → missingColumns.
 * - confirm step requires confirm=true; preview with 0 data rows → emptyFile.
 * - only fully valid rows are inserted; the rest stay in the error report.
 * - a phone race (unique violation) is silently counted as skipped.
 */
class PatientImportController extends Controller
{
    /** GET /patients/import — the wizard form. */
    public function show(Request $request): Response
    {
        return response()->view('patients.import', [
            'preview' => null,
            'done' => null,
            'localError' => null,
        ]);
    }

    /** POST /patients/import — preview or confirm-import. */
    public function store(Request $request): Response|RedirectResponse
    {
        $step = (string) $request->input('step', 'preview');
        $language = Settings::defaultLanguage();
        $existingPhones = Patient::query()->pluck('phone_e164')->all();

        if ($step === 'preview') {
            $file = $request->file('file');

            if ($file === null || $file->getSize() === 0) {
                return $this->renderError($request, 'noFile');
            }

            if ($file->getSize() > PatientImport::MAX_IMPORT_BYTES) {
                return $this->renderError($request, 'fileTooLarge');
            }

            $text = (string) file_get_contents($file->getRealPath());
        } else {
            $text = (string) $request->input('csv', '');

            if (strlen($text) > PatientImport::MAX_IMPORT_BYTES) {
                return $this->renderError($request, 'fileTooLarge');
            }

            if ((string) $request->input('confirm', '') !== 'true') {
                return $this->renderError($request, 'confirmationRequired');
            }
        }

        $result = PatientImport::parseImport($text, [
            'defaultLanguage' => $language,
            'existingPhones' => $existingPhones,
        ]);

        if ($result['missingColumns'] !== []) {
            return $this->renderError($request, 'missingColumns');
        }

        if ($result['tooManyRows']) {
            return $this->renderError($request, 'tooManyRows');
        }

        if ($step === 'preview') {
            if ($result['rows'] === []) {
                return $this->renderError($request, 'emptyFile');
            }

            return response()->view('patients.import', [
                'preview' => [
                    'csv' => $text,
                    'rows' => $result['rows'],
                    'validCount' => $result['validCount'],
                    'errorCount' => $result['errorCount'],
                ],
                'done' => null,
                'localError' => null,
            ]);
        }

        $imported = $this->importRows($result['rows'], $request->user());

        return response()->view('patients.import', [
            'preview' => null,
            'done' => [
                'imported' => $imported,
                'skipped' => count($result['rows']) - $imported,
            ],
            'localError' => null,
        ]);
    }

    /**
     * Insert every fully valid row; invalid rows and uniqueness races are
     * skipped. Each created row writes a PATIENT_CREATED audit.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function importRows(array $rows, object $actor): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            $values = $row['values'];
            if ($values === null) {
                continue;
            }

            try {
                $patient = DB::transaction(function () use ($values, $actor): Patient {
                    $patient = SystemId::createPatient([
                        'full_name' => $values['fullName'],
                        'phone_e164' => $values['phoneE164'],
                        'sex' => $values['sex'],
                        'age' => $values['age'],
                        'preferred_language' => $values['preferredLanguage'],
                        'notes_non_clinical' => null,
                        'active' => true,
                        'created_by' => $actor->id,
                    ]);

                    $tagIds = [];
                    foreach ($values['tags'] as $name) {
                        $tag = Tag::firstOrCreate(['name' => $name], ['color' => TagColors::for($name)]);
                        $tagIds[] = $tag->id;
                    }

                    if ($tagIds !== []) {
                        $patient->tags()->syncWithoutDetaching($tagIds);
                    }

                    return $patient;
                });
            } catch (UniqueConstraintViolationException) {
                // Raced into the DB by another import → counted as skipped.
                continue;
            }

            Audit::write('Patient', $patient->id, 'PATIENT_CREATED', null, [
                'fullName' => $patient->full_name,
                'phoneE164' => $patient->phone_e164,
                'sex' => $patient->sex,
                'age' => $patient->age,
                'preferredLanguage' => $patient->preferred_language,
                'notesNonClinical' => null,
                'doNotMessage' => false,
                'doNotMessageReason' => null,
                'idPhotoUrl' => null,
                'systemId' => $patient->system_id,
                'idNo' => null,
                'active' => true,
            ], ['id' => $actor->id, 'name' => $actor->name]);

            $imported++;
        }

        return $imported;
    }

    private function renderError(Request $request, string $key): Response
    {
        return response()->view('patients.import', [
            'preview' => null,
            'done' => null,
            'localError' => $key,
        ]);
    }
}
