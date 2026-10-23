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
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Ip;
use App\Support\Messaging\TaskGenerator;
use App\Support\Phone;
use App\Support\PublicSite\BookingValidation;
use App\Support\RateLimit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public, unauthenticated intake for the website's "book an appointment" form,
 * ported from src/app/api/public/requests/route.ts.
 *
 * When auto-approve is ON: creates a confirmed appointment directly.
 * When auto-approve is OFF: creates an appointment request for front desk review.
 *
 * Defences: honeypot field, per-IP rate limit, strict validation, and only the
 * sha-256 IP hash is kept — never the raw IP.
 */
class PublicBookingController extends Controller
{
    /** Booking spam guard: 5 submissions per hour per IP. */
    public const RATE_MAX = 5;

    public const RATE_WINDOW_MS = 60 * 60_000;

    /** `createdBy` marker — nobody is signed in behind a booking. */
    public const PUBLIC_BOOKING_ACTOR = 'public-booking';

    public function __invoke(Request $request): JsonResponse
    {
        $ip = Ip::clientIp($request->header('x-forwarded-for'));

        if (! RateLimit::check('public-booking:'.($ip ?? 'unknown'), self::RATE_MAX, self::RATE_WINDOW_MS)) {
            return response()->json(['ok' => false, 'error' => 'rateLimited'], 429);
        }

        // The raw body is decoded here (not via $request->json()) so an
        // unparseable payload is a 400 instead of an empty 422.
        $raw = (string) $request->getContent();
        $body = json_decode($raw, true);

        if ($raw === '' || json_last_error() !== JSON_ERROR_NONE || ! is_array($body)) {
            return response()->json(['ok' => false, 'error' => 'invalidJson'], 400);
        }

        $validated = BookingValidation::bookingForm($body);

        if ($validated['fields'] !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'validationError',
                'fields' => $validated['fields'],
            ], 422);
        }

        $values = $validated['values'];

        $phone = Phone::toE164($values['phone']);
        if ($phone === null) {
            return response()->json([
                'ok' => false,
                'error' => 'validationError',
                'fields' => ['phone' => 'invalidPhone'],
            ], 422);
        }

        $autoApprove = Settings::autoApproveAppointmentRequests();

        if ($autoApprove) {
            $result = $this->createPublicAppointment([
                'name' => $values['name'],
                'phoneE164' => $phone,
                'preferredDate' => $values['preferredDate'],
                'timeWindow' => $values['preferredTimeWindow'],
                'serviceInterest' => $values['serviceInterest'],
                'message' => $values['message'],
                'language' => $values['language'],
                'ipHash' => Ip::hashClientIp($ip),
            ]);
        } else {
            $result = $this->createPublicRequest([
                'name' => $values['name'],
                'phoneE164' => $phone,
                'preferredDate' => $values['preferredDate'],
                'timeWindow' => $values['preferredTimeWindow'],
                'serviceInterest' => $values['serviceInterest'],
                'message' => $values['message'],
                'language' => $values['language'],
                'ipHash' => Ip::hashClientIp($ip),
            ]);
        }

        if (isset($result['error'])) {
            $error = $result['error'];

            // Date/window problems are the visitor's to fix; show them inline.
            if ($error === 'invalidDate' || $error === 'pastSlot' || $error === 'noDoctorAvailable') {
                return response()->json([
                    'ok' => false,
                    'error' => 'validationError',
                    'fields' => ['preferredDate' => $error],
                ], 422);
            }

            return response()->json(['ok' => false, 'error' => $error], 500);
        }

        $response = [
            'ok' => true,
            'id' => $result['id'],
        ];

        if ($autoApprove && isset($result['appointment'])) {
            $response['appointment'] = $result['appointment'];
        }

        return response()->json($response)->header('Cache-Control', 'no-store');
    }

    /**
     * Creates the appointment directly (auto-confirmed). An existing patient with
     * the same phone is LINKED (never created) so the history is preserved.
     *
     * @param  array{name: string, phoneE164: string, preferredDate: string, timeWindow: string|null, serviceInterest: string|null, message: string|null, language: string, ipHash: string}  $input
     * @return array{id: string}|array{error: string}
     */
    private function createPublicAppointment(array $input): array
    {
        try {
            $tz = Settings::timezone();
            $now = ClinicTime::nowUtc();
            $todayKey = ClinicTime::clinicDateKey($now, $tz);
            $lastBookableKey = ClinicTime::dateKeyAdd($todayKey, Constants::MAX_REQUEST_DATE_DAYS_AHEAD);

            if ($input['preferredDate'] < $todayKey || $input['preferredDate'] > $lastBookableKey) {
                return ['error' => 'invalidDate'];
            }

            // "Today" with a window that has already gone by — nothing sensible
            // to ask for.
            $slot = Logic::slotForTimeWindow($input['timeWindow']);
            $instants = Logic::appointmentInstants(
                $input['preferredDate'],
                $slot['start'],
                Constants::DEFAULT_DURATION,
                $tz,
            );

            if ($instants['startsAt']->getTimestamp() <= $now->getTimestamp()) {
                return ['error' => 'pastSlot'];
            }

            // Find an available doctor for the requested time slot
            $doctor = $this->findAvailableDoctor($instants['startsAt'], $instants['endsAt'], $tz);
            if ($doctor === null) {
                return ['error' => 'noDoctorAvailable'];
            }

            $existing = Patient::query()
                ->where('phone_e164', $input['phoneE164'])
                ->first();

            $service = null;
            if ($input['serviceInterest'] !== null && $input['serviceInterest'] !== '') {
                $service = Service::query()
                    ->where('name', $input['serviceInterest'])
                    ->where('active', true)
                    ->first();
            }

            $appointmentId = DB::transaction(function () use ($input, $existing, $doctor, $service, $instants): string {
                // The visitor is matched by phone; a patient is created only when
                // nobody matches.
                $patient = $existing ?? Patient::create([
                    'full_name' => $input['name'],
                    'phone_e164' => $input['phoneE164'],
                    'preferred_language' => $input['language'] === 'am' ? 'am' : 'en',
                    'active' => true,
                    'created_by' => self::PUBLIC_BOOKING_ACTOR,
                ]);

                $appointment = Appointment::create([
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'starts_at' => $instants['startsAt'],
                    'ends_at' => $instants['endsAt'],
                    'service_id' => $service?->id,
                    'status' => Appointment::STATUS_CONFIRMED,
                    'created_from' => Appointment::SOURCE_WEBSITE,
                    'created_by' => self::PUBLIC_BOOKING_ACTOR,
                    'notes' => $input['message'] === '' ? null : $input['message'],
                ]);

                return $appointment->id;
            });

            Audit::write('Appointment', $appointmentId, 'APPOINTMENT_CREATED', null, [
                'source' => 'WEBSITE_REQUEST',
                'status' => Appointment::STATUS_CONFIRMED,
                'doctorId' => $doctor->id,
                'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
            ], null, null);

            // The new appointment gets its reminder / same-day messages.
            TaskGenerator::onAppointmentChanged($appointmentId);

            return [
                'id' => $appointmentId,
                'appointment' => [
                    'id' => $appointmentId,
                    'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
                    'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
                    'doctorName' => $doctor->full_name,
                ],
            ];
        } catch (\Throwable $e) {
            // The request failed for an unexpected reason: log it, but never
            // leak the internals to the visitor.
            report($e);

            return ['error' => 'unknownError'];
        }
    }

    /**
     * Files the request in the queue. An existing patient with the same phone
     * is LINKED (never created) so the front desk sees the history.
     *
     * @param  array{name: string, phoneE164: string, preferredDate: string, timeWindow: string|null, serviceInterest: string|null, message: string|null, language: string, ipHash: string}  $input
     * @return array{id: string}|array{error: string}
     */
    private function createPublicRequest(array $input): array
    {
        try {
            $tz = Settings::timezone();
            $now = ClinicTime::nowUtc();
            $todayKey = ClinicTime::clinicDateKey($now, $tz);
            $lastBookableKey = ClinicTime::dateKeyAdd($todayKey, Constants::MAX_REQUEST_DATE_DAYS_AHEAD);

            if ($input['preferredDate'] < $todayKey || $input['preferredDate'] > $lastBookableKey) {
                return ['error' => 'invalidDate'];
            }

            // "Today" with a window that has already gone by — nothing sensible
            // to ask for.
            $slot = Logic::slotForTimeWindow($input['timeWindow']);
            $instants = Logic::appointmentInstants(
                $input['preferredDate'],
                $slot['start'],
                Constants::DEFAULT_DURATION,
                $tz,
            );

            if ($instants['startsAt']->getTimestamp() <= $now->getTimestamp()) {
                return ['error' => 'pastSlot'];
            }

            $existing = Patient::query()
                ->where('phone_e164', $input['phoneE164'])
                ->first();

            $request = AppointmentRequest::create([
                'name' => $input['name'],
                'phone_e164' => $input['phoneE164'],
                'preferred_date' => $input['preferredDate'],
                'preferred_time_window' => $input['timeWindow'],
                'service_interest' => $input['serviceInterest'],
                'message' => $input['message'],
                'language' => $input['language'] === 'am' ? 'am' : 'en',
                'status' => AppointmentRequest::STATUS_NEW,
                'patient_id' => $existing?->id,
                'ip_hash' => $input['ipHash'],
            ]);

            Audit::write('AppointmentRequest', $request->id, 'REQUEST_CREATED', null, [
                'status' => AppointmentRequest::STATUS_NEW,
                'preferredDate' => $input['preferredDate'],
                'preferredTimeWindow' => $input['timeWindow'],
                'serviceInterest' => $input['serviceInterest'],
                'language' => $request->language,
                'patientId' => $existing?->id,
                'ipHash' => $input['ipHash'],
                // The raw IP is never stored — only the hash in `ipHash`.
            ], null, null);

            return ['id' => $request->id];
        } catch (\Throwable $e) {
            // The request failed for an unexpected reason: log it, but never
            // leak the internals to the visitor.
            report($e);

            return ['error' => 'unknownError'];
        }
    }

    /**
     * Finds an available doctor who has a shift covering the slot and no conflicts.
     */
    private function findAvailableDoctor(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt, string $tz): ?Staff
    {
        $candidate = [
            'doctorId' => '',
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
        ];

        // Get all active doctors
        $doctors = Staff::query()
            ->where('active', true)
            ->where('is_doctor', true)
            ->get(['id', 'full_name', 'specialty']);

        foreach ($doctors as $doctor) {
            $candidate['doctorId'] = $doctor->id;

            // Check shift coverage
            $shifts = ApptQueries::getDoctorShiftsForCoverage(
                $doctor->id,
                $startsAt,
                $endsAt
            );

            if (! Logic::isInsideShift($shifts, $startsAt, $endsAt)) {
                continue;
            }

            // Check approved leave
            $dateKey = ClinicTime::clinicDateKey($startsAt, $tz);
            $leaves = ApptQueries::getApprovedLeaveInRange(
                [$doctor->id],
                $dateKey,
                $dateKey,
                $tz
            );

            if (! empty($leaves)) {
                continue;
            }

            // Check double-booking
            $existingAppointments = ApptQueries::getStaffAppointmentsInPeriod(
                $doctor->id,
                $startsAt,
                $endsAt
            );

            $clashes = Logic::findDoctorDoubleBookings($existingAppointments, $candidate);
            if (! empty($clashes)) {
                continue;
            }

            // Found an available doctor
            return $doctor;
        }

        return null;
    }
}
