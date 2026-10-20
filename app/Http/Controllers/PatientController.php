<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Tag;
use App\Support\Appointments\ApptQueries;
use App\Support\Audit;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\TaskGenerator;
use App\Support\Patients\PatientImport;
use App\Support\Patients\PatientQueries;
use App\Support\Patients\PatientTabs;
use App\Support\Patients\PatientValidation;
use App\Support\Patients\SickLeaveQueries;
use App\Support\Patients\TagColors;
use App\Support\Phone;
use App\Support\Roles;
use App\Support\Sales\SaleQueries;
use App\Support\Settings;
use App\Support\Storage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patients CRUD + detail-page side actions, ported from
 * src/modules/patients/actions.ts and the patients pages.
 *
 * Rules preserved:
 * - PATIENT_ROLES = OWNER + RECEPTION; STAFF never reaches here (route gate).
 * - phone stored E.164, unique → duplicate blocks create/update.
 * - similar-name warning is non-blocking (second submit with confirm=1).
 * - no hard deletes: only `active` toggles.
 * - stop-messages re-enable is OWNER-only.
 * - vanity: id photo upload (PNG/JPEG/WebP ≤ 1 MiB) served from /uploads/{key}.
 * - every mutation writes an audit row; opening the detail page writes VIEW.
 */
class PatientController extends Controller
{
    /** Allowed for the surface (mirrors PATIENT_ROLES). */
    public const PATIENT_ROLES = [Roles::OWNER, Roles::RECEPTION];

    /** GET /patients — list + filter + sort + paginate + export links. */
    public function index(Request $request): Response
    {
        $q = mb_substr((string) $request->query('q', ''), 0, 100);
        $idNo = mb_substr((string) $request->query('idNo', ''), 0, 32);

        $dateFromRaw = mb_substr((string) $request->query('dateFrom', ''), 0, 10);
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw) === 1 ? $dateFromRaw : '';
        $dateToRaw = mb_substr((string) $request->query('dateTo', ''), 0, 10);
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw) === 1 ? $dateToRaw : '';

        $statusRaw = (string) $request->query('status', '');
        $status = in_array($statusRaw, ['true', 'false'], true) ? $statusRaw : '';

        $sort = PatientQueries::sortColumn($request->query('sort'));
        $dir = PatientQueries::sortDir($request->query('dir'));
        $page = (int) $request->query('page', 1);

        $filters = [
            'q' => $q,
            'idNo' => $idNo,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
        ];

        $result = PatientQueries::list($filters);

        return response()->view('patients.index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pageCount' => $result['pageCount'],
            'filters' => $filters,
            'pageSize' => PatientQueries::PAGE_SIZE,
        ]);
    }

    /** GET /patients/new — create form. */
    public function create(): Response
    {
        return response()->view('patients.form', [
            'mode' => 'create',
            'patient' => null,
            'route' => '/patients',
            'similar' => [],
            'duplicate' => null,
            'idPhotosEnabled' => Settings::patientIdPhotosEnabled(),
        ]);
    }

    /** POST /patients — create patient. */
    public function store(Request $request): RedirectResponse
    {
        $input = $this->formInput($request);
        $validated = PatientValidation::patientForm($input);

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput($request->except('idPhoto'));
        }

        $values = $validated['values'];

        $duplicate = PatientQueries::findByPhone($values['phone']);
        if ($duplicate !== null) {
            return back()->with('duplicatePatient', [
                'id' => $duplicate->id,
                'fullName' => $duplicate->full_name,
            ])->withInput($request->except('idPhoto'));
        }

        $confirmed = (string) $request->input('confirm', '') === 'true';

        if (! $confirmed) {
            $similar = PatientQueries::findSimilar($values['fullName'], $values['birthDate']);
            if ($similar !== []) {
                return back()->with('similarPatients', $similar)->withInput($request->except('idPhoto'));
            }
        }

        if ($request->hasFile('idPhoto') && ! Settings::patientIdPhotosEnabled()) {
            return back()->withErrors(['idPhoto' => 'idPhotoDisabled'])
                ->withInput($request->except('idPhoto'));
        }

        $language = $values['preferredLanguage'] ?? Settings::defaultLanguage();
        $idPhotoUrl = $this->storeIdPhoto($request);

        $actor = $request->user();

        try {
            $patient = Patient::create([
                'full_name' => $values['fullName'],
                'phone_e164' => $values['phone'],
                'sex' => $values['sex'],
                'birth_date' => $values['birthDate'],
                'preferred_language' => $language,
                'notes_non_clinical' => $values['notesNonClinical'],
                'id_no' => $values['idNo'],
                'id_photo_url' => $idPhotoUrl,
                'active' => true,
                'created_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = PatientQueries::findByPhone($values['phone']);

            return back()->with('duplicatePatient', $existing === null ? null : [
                'id' => $existing->id,
                'fullName' => $existing->full_name,
            ])->withInput($request->except('idPhoto'));
        }

        Audit::write('Patient', $patient->id, 'PATIENT_CREATED', null, $this->snapshot($patient), [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return redirect("/patients/{$patient->id}");
    }

    /** GET /patients/{id} — detail; sick tab reads `edit` for inline edit. */
    public function show(Request $request, string $id): Response
    {
        $patient = PatientQueries::find($id);
        abort_if($patient === null, 404);

        $this->logView($patient->id, $request->user());

        $tab = PatientTabs::from($request->query('tab'));
        $sickLeaveEnabled = Settings::patientSickLeaveEnabled();

        // The sick-leave tab does not exist while the feature is off.
        if (! $sickLeaveEnabled && $tab === 'sick') {
            $tab = 'overview';
        }

        $payload = PatientTabs::load($tab, $patient->id);

        $sickRows = $sickLeaveEnabled
            ? SickLeaveQueries::listForPatient($patient->id)
            : collect();
        $patientSales = SaleQueries::listPatientSales($patient->id);
        $patientAppointments = ApptQueries::listPatientAppointments($patient->id);
        $patientMessages = MsgQueries::listPatientMessages($patient->id);
        $editId = (string) $request->query('edit', '');
        $editing = $editId !== '' ? $sickRows->firstWhere('id', $editId) : null;

        return response()->view('patients.show', [
            'patient' => $patient,
            'tab' => $tab,
            'payload' => $payload,
            'allTags' => PatientQueries::listAllTags(),
            'sickRows' => $sickRows,
            'patientSales' => $patientSales,
            'patientAppointments' => $patientAppointments,
            'editing' => $editing,
            'timezone' => Settings::timezone(),
            'idPhotosEnabled' => Settings::patientIdPhotosEnabled(),
            'sickLeaveEnabled' => $sickLeaveEnabled,
        ]);
    }

    /** GET /patients/{id}/edit — edit form; 404 when patient missing. */
    public function edit(string $id): Response
    {
        $patient = PatientQueries::find($id);
        abort_if($patient === null, 404);

        return response()->view('patients.form', [
            'mode' => 'edit',
            'patient' => $patient,
            'route' => "/patients/{$patient->id}",
            'similar' => [],
            'duplicate' => null,
            'idPhotosEnabled' => Settings::patientIdPhotosEnabled(),
        ]);
    }

    /** POST /patients/{id} — update patient. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $before = Patient::find($id);
        abort_if($before === null, 404);

        $input = $this->formInput($request);
        $validated = PatientValidation::patientForm($input);

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput($request->except('idPhoto'));
        }

        $values = $validated['values'];

        $duplicate = PatientQueries::findByPhone($values['phone']);
        if ($duplicate !== null && $duplicate->id !== $id) {
            return back()->with('duplicatePatient', [
                'id' => $duplicate->id,
                'fullName' => $duplicate->full_name,
            ])->withInput($request->except('idPhoto'));
        }

        $confirmed = (string) $request->input('confirm', '') === 'true';

        if (! $confirmed) {
            $similar = PatientQueries::findSimilar($values['fullName'], $values['birthDate'], $id);
            if ($similar !== []) {
                return back()->with('similarPatients', $similar)->withInput($request->except('idPhoto'));
            }
        }

        if ($request->hasFile('idPhoto') && ! Settings::patientIdPhotosEnabled()) {
            return back()->withErrors(['idPhoto' => 'idPhotoDisabled'])
                ->withInput($request->except('idPhoto'));
        }

        $language = $values['preferredLanguage'] ?? $before->preferred_language;
        $idPhotoUrl = $request->hasFile('idPhoto')
            ? $this->storeIdPhoto($request)
            : $before->id_photo_url;

        $beforeSnapshot = $this->snapshot($before);
        $oldPhotoUrl = $before->id_photo_url;

        $before->fill([
            'full_name' => $values['fullName'],
            'phone_e164' => $values['phone'],
            'sex' => $values['sex'],
            'birth_date' => $values['birthDate'],
            'preferred_language' => $language,
            'notes_non_clinical' => $values['notesNonClinical'],
            'id_no' => $values['idNo'],
            'id_photo_url' => $idPhotoUrl,
        ])->save();

        // Replace the old blob only after a successful save (best-effort).
        if ($request->hasFile('idPhoto') && $oldPhotoUrl !== null && $oldPhotoUrl !== $idPhotoUrl) {
            $oldKey = Storage::keyFromUrl($oldPhotoUrl);
            if ($oldKey !== null) {
                try {
                    Storage::remove($oldKey);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        $actor = $request->user();
        Audit::write('Patient', $id, 'PATIENT_UPDATED', $beforeSnapshot, $this->snapshot($before->fresh()), [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return redirect("/patients/{$id}");
    }

    /** POST /patients/{id}/tags — replace the patient's tag set. */
    public function setTags(Request $request, string $id): RedirectResponse
    {
        $names = [];
        foreach (explode(',', (string) $request->input('tags', '')) as $name) {
            $name = mb_strtolower(trim($name), 'UTF-8');
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);

        foreach ($names as $name) {
            if (mb_strlen($name, 'UTF-8') > PatientImport::MAX_TAG_LENGTH) {
                return back()->withErrors(['tags' => 'tooLong']);
            }
        }

        if (count($names) > PatientImport::MAX_TAGS_PER_PATIENT) {
            return back()->withErrors(['tags' => 'tooMany']);
        }

        $patient = Patient::with('tags')->find($id);
        abort_if($patient === null, 404);

        $before = $patient->tags->pluck('name')->all();
        $changed = count($before) !== count($names) || array_diff($before, $names) !== [];

        if ($changed) {
            $tagIds = [];
            foreach ($names as $name) {
                $tag = Tag::firstOrCreate(['name' => $name], ['color' => TagColors::for($name)]);
                $tagIds[] = $tag->id;
            }

            DB::transaction(function () use ($patient, $tagIds): void {
                $patient->tags()->detach();
                $patient->tags()->attach($tagIds);
            });

            $actor = $request->user();
            Audit::write('Patient', $id, 'PATIENT_TAGS_CHANGED', [
                'tags' => collect($before)->sort()->values()->all(),
            ], [
                'tags' => collect($names)->sort()->values()->all(),
            ], ['id' => $actor->id, 'name' => $actor->name]);
        }

        return back();
    }

    /** POST /patients/{id}/status — toggle active flag (idempotent no-op). */
    public function setActive(Request $request, string $id): RedirectResponse
    {
        $active = (string) $request->input('active', '') === 'true';

        $before = Patient::find($id);
        abort_if($before === null, 404);

        if ((bool) $before->active === $active) {
            return back();
        }

        // Capture the previous value before saving: after save() getOriginal()
        // returns the new value, which would erase the audit diff.
        $wasActive = (bool) $before->active;

        $before->forceFill(['active' => $active])->save();

        $actor = $request->user();
        Audit::write('Patient', $id, 'PATIENT_STATUS_CHANGED', [
            'active' => $wasActive,
        ], [
            'active' => $active,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /patients/{id}/do-not-message — stop / resume messaging. */
    public function setDoNotMessage(Request $request, string $id): RedirectResponse
    {
        $stop = (string) $request->input('stop', '') === 'true';
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 300);

        if ($stop && $reason === '') {
            return back()->withErrors(['reason' => 'required']);
        }

        // Re-enabling messages after an opt-out is OWNER-only.
        if (! $stop && $request->user()->role !== Roles::OWNER) {
            abort(403, 'This action is not allowed for your role.');
        }

        $before = Patient::find($id);
        abort_if($before === null, 404);

        if ((bool) $before->do_not_message === $stop && ($stop ? $before->do_not_message_reason === $reason : true)) {
            return back();
        }

        $beforeReason = $before->do_not_message_reason;
        $beforeStatus = (bool) $before->do_not_message;
        $newReason = $stop ? $reason : null;

        $before->forceFill([
            'do_not_message' => $stop,
            'do_not_message_reason' => $newReason,
        ])->save();

        if ($stop) {
            // Opting out cancels every pending message.
            $this->cancelPendingForPatient($id);
        } else {
            // Consent given again: prepare the welcome message.
            TaskGenerator::onPatientConsentGiven($id);
        }

        $actor = $request->user();
        Audit::write('Patient', $id, 'PATIENT_DNM_CHANGED', [
            'doNotMessage' => $beforeStatus,
            'reason' => $beforeReason,
        ], [
            'doNotMessage' => $stop,
            'reason' => $newReason,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /patients/{id}/photo — capture/upload an ID photo on the detail page. */
    public function capturePhoto(Request $request, string $id): RedirectResponse
    {
        // ID photos are opt-in (the setting is off by default).
        if (! Settings::patientIdPhotosEnabled()) {
            abort(403, 'Patient ID photos are disabled.');
        }

        if (! $request->hasFile('idPhoto')) {
            return back()->withErrors(['idPhoto' => 'noFile']);
        }

        $patient = Patient::find($id);
        abort_if($patient === null, 404);

        $file = $request->file('idPhoto');
        $extension = PatientValidation::idPhotoExtension((string) $file->getMimeType());

        if ($extension === null) {
            return back()->withErrors(['idPhoto' => 'photoInvalidType']);
        }

        if ($file->getSize() > PatientValidation::MAX_ID_PHOTO_BYTES) {
            return back()->withErrors(['idPhoto' => 'photoTooLarge']);
        }

        $oldUrl = $patient->id_photo_url;
        $key = 'patient-id-'.(string) Str::uuid().'.'.$extension;
        Storage::put($key, (string) file_get_contents($file->getRealPath()));
        $idPhotoUrl = '/uploads/'.$key;

        $patient->forceFill(['id_photo_url' => $idPhotoUrl])->save();

        if ($oldUrl !== null && $oldUrl !== $idPhotoUrl) {
            $oldKey = Storage::keyFromUrl($oldUrl);
            if ($oldKey !== null) {
                try {
                    Storage::remove($oldKey);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        $actor = $request->user();
        Audit::write('Patient', $id, 'PATIENT_ID_PHOTO_CHANGED', [
            'idPhotoUrl' => $oldUrl,
        ], [
            'idPhotoUrl' => $idPhotoUrl,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** Read the raw form fields (matches legacy readPatientForm). */
    private function formInput(Request $request): array
    {
        $file = $request->file('idPhoto');

        return [
            'fullName' => (string) $request->input('fullName', ''),
            'phone' => (string) $request->input('phone', ''),
            'sex' => (string) $request->input('sex', ''),
            'birthDate' => (string) $request->input('birthDate', ''),
            'preferredLanguage' => (string) $request->input('preferredLanguage', ''),
            'notesNonClinical' => (string) $request->input('notesNonClinical', ''),
            'idNo' => (string) $request->input('idNo', ''),
            'idPhoto' => $file !== null ? ['type' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()] : null,
        ];
    }

    /** Store an uploaded ID photo and return its /uploads URL, or null. */
    private function storeIdPhoto(Request $request): ?string
    {
        // The feature is opt-in: without it a photo is never stored.
        if (! Settings::patientIdPhotosEnabled()) {
            return null;
        }

        if (! $request->hasFile('idPhoto')) {
            return null;
        }

        $file = $request->file('idPhoto');
        $extension = PatientValidation::idPhotoExtension((string) $file->getMimeType());

        if ($extension === null) {
            return null;
        }

        $key = 'patient-id-'.(string) Str::uuid().'.'.$extension;
        Storage::put($key, (string) file_get_contents($file->getRealPath()));

        return '/uploads/'.$key;
    }

    /**
     * The 10-field audit snapshot (excludes tags/createdBy/timestamps).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Patient $patient): array
    {
        return [
            'fullName' => $patient->full_name,
            'phoneE164' => $patient->phone_e164,
            'sex' => $patient->sex,
            'birthDate' => $patient->birth_date?->format('Y-m-d'),
            'preferredLanguage' => $patient->preferred_language,
            'notesNonClinical' => $patient->notes_non_clinical,
            'doNotMessage' => (bool) $patient->do_not_message,
            'doNotMessageReason' => $patient->do_not_message_reason,
            'idPhotoUrl' => $patient->id_photo_url,
            'idNo' => $patient->id_no,
            'active' => (bool) $patient->active,
        ];
    }

    /** Audit a detail-page open (VIEW), deduped per user+patient per minute. */
    private function logView(string $patientId, object $user): void
    {
        try {
            Audit::write('Patient', $patientId, 'VIEW', null, null, [
                'id' => $user->id,
                'name' => $user->name,
            ]);
        } catch (\Throwable) {
            // Audit failures never block the page.
        }
    }

    /**
     * Cancel every open message task for the patient (the opt-out flow).
     */
    private function cancelPendingForPatient(string $patientId): void
    {
        TaskGenerator::cancelPendingForPatient($patientId);
    }

    /** Legacy `formatPhoneDisplay` used by views for the pre-filled phone input. */
    public static function displayPhone(?string $e64): string
    {
        return Phone::display($e64) ?? '';
    }
}
