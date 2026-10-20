<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Support\Appointments\ApptQueries;
use App\Support\Appointments\ApptValidation;
use App\Support\Appointments\Logic;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The follow-up board: the tabs, the patient filter, the create form and the
 * mark-done / cancel actions.
 *
 * Rules preserved:
 * - Only OPEN follow-ups can change status (no override exists here).
 * - The due date comes from a preset (2w / 1m / 3m / 6m / custom) computed
 *   against the clinic-local today.
 */
class FollowUpController extends Controller
{
    /** GET /follow-ups?status=open|overdue|done|cancelled&patient=<id> */
    public function index(Request $request): Response
    {
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        $statusRaw = (string) $request->query('status', 'open');
        $tab = in_array($statusRaw, ['open', 'overdue', 'done', 'cancelled'], true) ? $statusRaw : 'open';

        $patientId = mb_substr((string) $request->query('patient', ''), 0, 64);
        $patientId = $patientId === '' ? null : $patientId;

        $params = ['patientId' => $patientId];

        if ($tab === 'open') {
            $params['status'] = FollowUp::STATUS_OPEN;
        } elseif ($tab === 'overdue') {
            $params['status'] = FollowUp::STATUS_OPEN;
            $params['dueBeforeKey'] = $todayKey;
        } elseif ($tab === 'done') {
            $params['status'] = FollowUp::STATUS_DONE;
        } else {
            $params['status'] = FollowUp::STATUS_CANCELLED;
        }

        return response()->view('followups.index', [
            'tab' => $tab,
            'patientId' => $patientId,
            'rows' => ApptQueries::listFollowUps($params),
            'patients' => ApptQueries::listPatientOptions(),
            'overdueCount' => ApptQueries::countOpenFollowUpsOverdue($todayKey, $tz),
            'todayKey' => $todayKey,
            'timezone' => $tz,
        ]);
    }

    /** GET /follow-ups/new — the create form. */
    public function create(Request $request): Response
    {
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        return response()->view('followups.form', [
            'patientId' => mb_substr((string) $request->query('patientId', ''), 0, 64),
            'appointmentId' => mb_substr((string) $request->query('appointmentId', ''), 0, 64),
            'timezone' => $tz,
            'todayKey' => $todayKey,
        ]);
    }

    /** POST /follow-ups — create a follow-up. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = ApptValidation::followUpForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        $dueKey = $values['preset'] === 'custom'
            ? (string) $values['dueDate']
            : Logic::followUpDueKey($todayKey, $values['preset']);

        $followUp = FollowUp::create([
            'patient_id' => $values['patientId'],
            'due_date' => $dueKey,
            'reason' => $values['reason'],
            'status' => FollowUp::STATUS_OPEN,
            'created_from_appointment_id' => $values['appointmentId'],
            'created_by' => $actor->id,
        ]);

        Audit::write('FollowUp', $followUp->id, 'FOLLOW_UP_CREATED', null, [
            'patientId' => $values['patientId'],
            'dueDate' => $dueKey,
            'reason' => $values['reason'],
            'createdFromAppointmentId' => $values['appointmentId'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /follow-ups/{id}/status — mark done or cancel. */
    public function setStatus(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $validated = ApptValidation::followUpStatus($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $followUp = FollowUp::find($id);
        if ($followUp === null) {
            return back()->with('actionError', 'notFound');
        }

        $from = (string) $followUp->status;
        if ($from === FollowUp::STATUS_DONE || $from === FollowUp::STATUS_CANCELLED) {
            return back()->with('actionError', 'invalidTransition');
        }

        $followUp->forceFill(['status' => $values['status']])->save();

        Audit::write('FollowUp', $id, 'FOLLOW_UP_STATUS_CHANGED',
            ['status' => $from],
            ['status' => $values['status']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }
}
