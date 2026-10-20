<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Staff;
use App\Support\Appointments\ApptQueries;
use App\Support\Appointments\Constants;
use App\Support\Appointments\Logic;
use App\Support\Appointments\RequestQueries;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Messaging\TaskGenerator;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website request queue (the front desk).
 *
 * Rules preserved:
 * - A public booking is a REQUEST — it never becomes an appointment until
 *   somebody here converts it.
 * - Converting matches the visitor by phone (or creates the patient), books a
 *   SCHEDULED appointment for the preferred day + window with the chosen
 *   doctor, and prepares the appointment messages.
 * - A converted request can never be converted (or re-statused) again.
 * - Nothing is ever hard-deleted: the status is the state.
 */
class RequestController extends Controller
{
    /** GET /requests?status=NEW|CONTACTED|CONVERTED|DECLINED|SPAM */
    public function index(Request $request): Response
    {
        $tz = Settings::timezone();

        $statusRaw = strtoupper((string) $request->query('status', ''));
        $status = in_array($statusRaw, AppointmentRequest::STATUSES, true) ? $statusRaw : null;

        $rows = RequestQueries::listRequests($status, $tz);

        return response()->view('requests.index', [
            'status' => $status,
            'rows' => $rows,
            'newCount' => $status === null ? RequestQueries::countNewRequests() : null,
            'doctors' => ApptQueries::listDoctorOptions(),
            'services' => Service::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'patients' => ApptQueries::listPatientOptions(),
            'timezone' => $tz,
        ]);
    }

    /**
     * POST /requests/{id}/convert — turn the request into a real appointment.
     */
    public function convert(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $doctorId = mb_substr((string) $request->input('doctorId', ''), 0, 64);
        $serviceId = mb_substr((string) $request->input('serviceId', ''), 0, 64);
        $patientId = mb_substr((string) $request->input('patientId', ''), 0, 64);
        $notes = mb_substr(trim((string) $request->input('notes', '')), 0, Constants::MAX_APPT_NOTES);

        if ($doctorId === '') {
            return back()->withErrors(['doctorId' => 'required']);
        }

        $row = AppointmentRequest::query()->find($id);

        if ($row === null) {
            abort(404);
        }

        if ($row->appointment_id !== null || (string) $row->status === AppointmentRequest::STATUS_CONVERTED) {
            return back()->with('actionError', 'requests.alreadyConverted');
        }

        $doctor = Staff::query()->find($doctorId);

        if ($doctor === null || ! $doctor->active || ! $doctor->is_doctor) {
            return back()->withErrors(['doctorId' => 'required']);
        }

        $service = $serviceId === ''
            ? null
            : Service::query()->find($serviceId);

        $tz = Settings::timezone();
        $slot = Logic::slotForTimeWindow($row->preferred_time_window);
        $instants = Logic::appointmentInstants(
            (string) $row->preferred_date?->format('Y-m-d'),
            $slot['start'],
            Constants::DEFAULT_DURATION,
            $tz,
        );

        $appointmentId = DB::transaction(function () use ($row, $doctor, $service, $patientId, $notes, $actor, $instants): string {
            // The visitor is matched by phone; a patient is created only when
            // nobody matches.
            $patient = $patientId !== ''
                ? Patient::query()->find($patientId)
                : Patient::query()->where('phone_e164', $row->phone_e164)->first();

            if ($patient === null) {
                $patient = Patient::create([
                    'full_name' => (string) $row->name,
                    'phone_e164' => (string) $row->phone_e164,
                    'preferred_language' => (string) $row->language === 'am' ? 'am' : 'en',
                    'active' => true,
                    'created_by' => $actor->id,
                ]);
            }

            $appointment = Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'starts_at' => $instants['startsAt'],
                'ends_at' => $instants['endsAt'],
                'service_id' => $service?->id,
                'status' => Appointment::STATUS_SCHEDULED,
                'created_from' => Appointment::SOURCE_WEBSITE,
                'created_by' => $actor->id,
                'notes' => $notes === '' ? null : $notes,
            ]);

            $row->forceFill([
                'status' => AppointmentRequest::STATUS_CONVERTED,
                'handled_by' => $actor->id,
                'handled_at' => ClinicTime::nowUtc(),
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
            ])->save();

            return $appointment->id;
        });

        Audit::write('AppointmentRequest', $row->id, 'REQUEST_CONVERTED', null, [
            'status' => AppointmentRequest::STATUS_CONVERTED,
            'appointmentId' => $appointmentId,
            'doctorId' => $doctor->id,
            'patientId' => $row->patient_id,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
        ], ['id' => $actor->id, 'name' => $actor->name]);

        Audit::write('Appointment', $appointmentId, 'APPOINTMENT_CREATED', null, [
            'source' => 'WEBSITE_REQUEST',
            'status' => Appointment::STATUS_SCHEDULED,
            'doctorId' => $doctor->id,
            'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
            'requestId' => $row->id,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        // The new appointment gets its reminder / same-day messages.
        TaskGenerator::onAppointmentChanged($appointmentId);

        return back()->with('actionOk', 'requests.converted');
    }

    /**
     * POST /requests/{id}/status — CONTACTED / DECLINED / SPAM.
     */
    public function setStatus(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $status = strtoupper((string) $request->input('status', ''));

        if (! in_array($status, [AppointmentRequest::STATUS_CONTACTED, AppointmentRequest::STATUS_DECLINED, AppointmentRequest::STATUS_SPAM], true)) {
            return back()->withErrors(['status' => 'required']);
        }

        $row = AppointmentRequest::query()->find($id);

        if ($row === null) {
            abort(404);
        }

        if ($row->appointment_id !== null || (string) $row->status === AppointmentRequest::STATUS_CONVERTED) {
            return back()->with('actionError', 'requests.alreadyConverted');
        }

        $before = (string) $row->status;

        $row->forceFill([
            'status' => $status,
            'handled_by' => $row->handled_by ?: $actor->id,
            'handled_at' => $row->handled_at ?: ClinicTime::nowUtc(),
        ])->save();

        Audit::write(
            'AppointmentRequest',
            $row->id,
            'REQUEST_STATUS_CHANGED',
            ['status' => $before],
            ['status' => $status],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back()->with('actionOk', 'requests.statusChanged');
    }
}
