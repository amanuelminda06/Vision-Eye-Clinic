<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Staff;
use App\Support\Appointments\ApptQueries;
use App\Support\Appointments\ApptRoles;
use App\Support\Appointments\ApptValidation;
use App\Support\Appointments\Constants;
use App\Support\Appointments\Logic;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Hr\Shifts;
use App\Support\Messaging\TaskGenerator;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The appointment board: list/day/week views, the create/edit form, the status
 * transitions and the double-booking warning.
 *
 * Rules preserved:
 * - OWNER + RECEPTION only; STAFF is redirected to /403.
 * - Terminal states are frozen for RECEPTION — only the OWNER may move out of
 *   them (and that correction is audited).
 * - Cancelling needs a reason.
 * - Double-booking / outside-shift / on-leave are WARNINGS, never blockers.
 */
class AppointmentController extends Controller
{
    /** GET /appointments?view=list|day|week&day=YYYY-MM-DD&doctor=<staffId> */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $todayKey = ClinicTime::clinicDateKey($now, $tz);

        $viewRaw = (string) $request->query('view', 'list');
        $view = in_array($viewRaw, ['list', 'day', 'week'], true) ? $viewRaw : 'list';

        $dayRaw = (string) $request->query('day', '');
        $dayKey = preg_match(Constants::DATE_RE, $dayRaw) === 1 ? $dayRaw : $todayKey;

        $doctorId = mb_substr((string) $request->query('doctor', ''), 0, 64);
        $doctorId = $doctorId === '' ? null : $doctorId;

        [$fromKey, $toKey] = $this->viewRange($view, $dayKey);

        // The range is inclusive of both day keys, so the exclusive end
        // instant is the midnight AFTER the last day.
        $from = ClinicTime::combineDateAndTime($fromKey, '00:00', $tz);
        $to = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($toKey, 1), '00:00', $tz);

        $rows = ApptQueries::listAppointments([
            'from' => $from,
            'to' => $to,
            'doctorId' => $doctorId,
        ]);

        // The week view groups by clinic-local day.
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[ClinicTime::clinicDateKey($row['startsAt'], $tz)][] = $row;
        }

        $step = $view === 'week' ? 7 : 1;

        return response()->view('appointments.index', [
            'view' => $view,
            'dayKey' => $dayKey,
            'todayKey' => $todayKey,
            'prevKey' => ClinicTime::dateKeyAdd($dayKey, -$step),
            'nextKey' => ClinicTime::dateKeyAdd($dayKey, $step),
            'doctorId' => $doctorId,
            'rows' => $rows,
            'byDay' => $byDay,
            'days' => $view === 'week' ? Shifts::weekDays(Shifts::weekStartKey($dayKey)) : [],
            'doctors' => ApptQueries::listDoctorOptions(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canOverride' => ApptRoles::canOverrideTerminalTransition($actor->role),
            'timezone' => $tz,
        ]);
    }

    /** GET /appointments/new — the create form. */
    public function create(Request $request): Response
    {
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        return response()->view('appointments.form', [
            'appointment' => null,
            'action' => route('appointments.store'),
            'doctors' => ApptQueries::listDoctorOptions(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'patients' => ApptQueries::listPatientOptions(),
            'defaultDateKey' => $todayKey,
        ]);
    }

    /** GET /appointments/{id}/edit — the edit form. */
    public function edit(string $id): Response
    {
        $appointment = ApptQueries::getAppointment($id);

        if ($appointment === null) {
            abort(404);
        }

        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        return response()->view('appointments.form', [
            'appointment' => $appointment,
            'action' => route('appointments.update', $id),
            'doctors' => ApptQueries::listDoctorOptions(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'patients' => ApptQueries::listPatientOptions(),
            'defaultDateKey' => $todayKey,
        ]);
    }

    /** POST /appointments — create an appointment. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = ApptValidation::appointmentForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];
        $tz = Settings::timezone();

        $doctor = Staff::find($values['doctorId']);
        if ($doctor === null || ! $doctor->active || ! $doctor->is_doctor) {
            return back()->with('actionError', 'doctorRequired')->withInput();
        }

        $patientId = $this->resolvePatient($values, $actor->id);

        if ($patientId === null) {
            return back()->with('actionError', 'patientRequired')->withInput();
        }

        $serviceId = null;
        if ($values['serviceId'] !== null) {
            $service = Service::find($values['serviceId']);
            if ($service === null) {
                return back()->withErrors(['serviceId' => 'invalid'])->withInput();
            }
            $serviceId = $service->id;
        }

        $instants = Logic::appointmentInstants($values['dateKey'], $values['startTime'], $values['durationMin'], $tz);

        $warnings = $this->warningsFor($doctor->id, $instants, $tz);

        $clashes = $this->doubleBookings($doctor->id, $instants, null);

        if ($clashes !== [] && ! $values['overrideDoubleBooking']) {
            return back()
                ->withInput()
                ->with('warnings', ['doubleBooking'])
                ->with('doubleBooked', true);
        }

        $appointment = Appointment::create([
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'service_id' => $serviceId,
            'status' => Appointment::STATUS_SCHEDULED,
            'notes' => $values['notes'],
            'created_from' => Appointment::SOURCE_STAFF,
            'created_by' => $actor->id,
        ]);

        if ($clashes !== [] && $values['overrideDoubleBooking']) {
            Audit::write('Appointment', $appointment->id, 'APPOINTMENT_DOUBLE_BOOKED', null, [
                'doctorId' => $doctor->id,
                'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'clashCount' => count($clashes),
            ], ['id' => $actor->id, 'name' => $actor->name]);
        }

        Audit::write('Appointment', $appointment->id, 'APPOINTMENT_CREATED', null, [
            'patientId' => $patientId,
            'doctorId' => $doctor->id,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'serviceId' => $serviceId,
            'notes' => $values['notes'],
            'warnings' => $warnings,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        // Prepare the reminder / same-day / confirmation messages.
        TaskGenerator::onAppointmentChanged($appointment->id);

        return back()->with('warnings', $warnings);
    }

    /** POST /appointments/{id} — update an appointment. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $validated = ApptValidation::appointmentForm($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];
        $tz = Settings::timezone();

        $before = Appointment::find($id);
        if ($before === null) {
            return back()->with('actionError', 'notFound');
        }

        // A terminal appointment is frozen for everyone but the OWNER.
        if (Logic::isTerminalStatus((string) $before->status)
            && ! ApptRoles::canOverrideTerminalTransition($actor->role)) {
            return back()->with('actionError', 'terminalLocked');
        }

        $doctor = Staff::find($values['doctorId']);
        if ($doctor === null || ! $doctor->active || ! $doctor->is_doctor) {
            return back()->with('actionError', 'doctorRequired')->withInput();
        }

        $patientId = $this->resolvePatient($values, $actor->id);

        if ($patientId === null) {
            return back()->with('actionError', 'patientRequired')->withInput();
        }

        $serviceId = $values['serviceId'];
        if ($serviceId !== null && Service::find($serviceId) === null) {
            return back()->withErrors(['serviceId' => 'invalid'])->withInput();
        }

        $instants = Logic::appointmentInstants($values['dateKey'], $values['startTime'], $values['durationMin'], $tz);

        $clashes = $this->doubleBookings($doctor->id, $instants, $id);
        if ($clashes !== [] && ! $values['overrideDoubleBooking']) {
            return back()->withInput()->with('warnings', ['doubleBooking'])->with('doubleBooked', true);
        }

        $old = [
            'patientId' => $before->patient_id,
            'doctorId' => $before->doctor_id,
            'serviceId' => $before->service_id,
            'startsAt' => $before->starts_at?->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $before->ends_at?->format('Y-m-d\TH:i:s.v\Z'),
        ];

        // Captured BEFORE the write: the reschedule hook needs the old instant.
        $previousStartsAt = $before->starts_at;

        $before->forceFill([
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'starts_at' => $instants['startsAt'],
            'ends_at' => $instants['endsAt'],
            'service_id' => $serviceId,
            'notes' => $values['notes'],
        ])->save();

        Audit::write('Appointment', $id, 'APPOINTMENT_UPDATED', $old, [
            'patientId' => $patientId,
            'doctorId' => $doctor->id,
            'serviceId' => $serviceId,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
        ], ['id' => $actor->id, 'name' => $actor->name]);

        // A reschedule cancels the old reminders and prepares a versioned one.
        TaskGenerator::onAppointmentChanged($id, $previousStartsAt);

        if ($clashes !== [] && $values['overrideDoubleBooking']) {
            Audit::write('Appointment', $id, 'APPOINTMENT_DOUBLE_BOOKED', null, [
                'doctorId' => $doctor->id,
                'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'clashCount' => count($clashes),
            ], ['id' => $actor->id, 'name' => $actor->name]);
        }

        return back()->with('warnings', $this->warningsFor($doctor->id, $instants, $tz));
    }

    /** POST /appointments/{id}/status — a status transition. */
    public function setStatus(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $validated = ApptValidation::appointmentStatus($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $appointment = Appointment::find($id);
        if ($appointment === null) {
            return back()->with('actionError', 'notFound');
        }

        $from = (string) $appointment->status;
        $to = $values['status'];

        $ownerOverride = ApptRoles::canOverrideTerminalTransition($actor->role);

        if (! Logic::transitionAllowed($from, $to, ['ownerOverride' => $ownerOverride])) {
            return back()->with('actionError', Logic::isTerminalStatus($from) ? 'terminalLocked' : 'invalidTransition');
        }

        // Cancelling always needs a reason.
        if ($to === Appointment::STATUS_CANCELLED && ($values['cancelReason'] === null || $values['cancelReason'] === '')) {
            return back()->with('actionError', 'cancelReasonRequired');
        }

        $appointment->forceFill([
            'status' => $to,
            // A reason is only written when the new status is CANCELLED.
            'cancel_reason' => $to === Appointment::STATUS_CANCELLED ? $values['cancelReason'] : $appointment->cancel_reason,
        ])->save();

        Audit::write('Appointment', $id, 'APPOINTMENT_STATUS_CHANGED',
            ['status' => $from],
            ['status' => $to, 'cancelReason' => $to === Appointment::STATUS_CANCELLED ? $values['cancelReason'] : $appointment->cancel_reason],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        // A completion, a no-show or a cancellation cancels the pending
        // reminders and prepares the matching message.
        TaskGenerator::onAppointmentChanged($id);

        return back();
    }

    /**
     * The view range (inclusive clinic-local day keys).
     *
     * @return array{0: string, 1: string}
     */
    private function viewRange(string $view, string $dayKey): array
    {
        return match ($view) {
            'day' => [$dayKey, $dayKey],
            'week' => [
                Shifts::weekStartKey($dayKey),
                ClinicTime::dateKeyAdd(Shifts::weekStartKey($dayKey), 6),
            ],
            default => [$dayKey, ClinicTime::dateKeyAdd($dayKey, 14)],
        };
    }

    /**
     * Resolves the patient: an existing one or a quick-create.
     *
     * @param  array<string, mixed>  $values
     */
    private function resolvePatient(array $values, string $actorId): ?string
    {
        if ($values['patientId'] !== null) {
            $patient = Patient::find($values['patientId']);

            if ($patient === null || ! $patient->active) {
                return null;
            }

            return $patient->id;
        }

        if ($values['quickName'] === null || $values['quickPhone'] === null) {
            return null;
        }

        $phone = Phone::toE164($values['quickPhone']);
        if ($phone === null) {
            return null;
        }

        $existing = Patient::query()->where('phone_e164', $phone)->first();
        if ($existing !== null) {
            return $existing->active ? $existing->id : null;
        }

        $created = Patient::create([
            'full_name' => $values['quickName'],
            'phone_e164' => $phone,
            'preferred_language' => 'en',
            'active' => true,
            'created_by' => $actorId,
        ]);

        return $created->id;
    }

    /**
     * The non-blocking coverage advisories.
     *
     * @return list<string>
     */
    private function warningsFor(string $doctorId, array $instants, string $tz): array
    {
        $fromKey = ClinicTime::clinicDateKey($instants['startsAt'], $tz);
        $toKey = ClinicTime::clinicDateKey($instants['endsAt'], $tz);

        $shifts = ApptQueries::getDoctorShiftsForCoverage(
            $doctorId,
            ClinicTime::combineDateAndTime($fromKey, '00:00', $tz),
            ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($toKey, 1), '00:00', $tz),
        );

        $leaves = ApptQueries::getApprovedLeaveInRange([$doctorId], $fromKey, $toKey, $tz);

        return Logic::appointmentCoverageWarnings([
            'doctorId' => $doctorId,
            'startsAt' => $instants['startsAt'],
            'endsAt' => $instants['endsAt'],
        ], $shifts, $leaves, $tz);
    }

    /**
     * The overlapping active appointments of the same doctor.
     *
     * @return list<array<string, mixed>>
     */
    private function doubleBookings(string $doctorId, array $instants, ?string $ignoreId): array
    {
        $existing = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '<', $instants['endsAt'])
            ->where('ends_at', '>', $instants['startsAt'])
            ->get()
            ->map(fn (Appointment $row): array => [
                'id' => $row->id,
                'doctorId' => $row->doctor_id,
                'startsAt' => $row->starts_at,
                'endsAt' => $row->ends_at,
                'status' => (string) $row->status,
            ])->all();

        return Logic::findDoctorDoubleBookings($existing, [
            'id' => $ignoreId,
            'doctorId' => $doctorId,
            'startsAt' => $instants['startsAt'],
            'endsAt' => $instants['endsAt'],
        ]);
    }
}
