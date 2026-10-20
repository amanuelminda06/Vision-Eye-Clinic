<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Staff;
use App\Support\Appointments\Constants;
use App\Support\Appointments\Logic;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Ip;
use App\Support\Phone;
use App\Support\PublicSite\BookingValidation;
use App\Support\RateLimit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public, unauthenticated intake for the website's "book an appointment" form,
 * ported from src/app/api/public/requests/route.ts.
 *
 * The booking is auto-approved: it becomes a CONFIRMED appointment (doctor
 * assigned server-side) instead of a request to review.
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

        // The honeypot is checked BEFORE the schema: a bot that fills it gets a
        // silent 200 and learns nothing (the legacy route checked it after
        // validation, where the schema had already rejected the post).
        if (BookingValidation::isHoneypotTripped($body['website'] ?? '')) {
            return response()->json(['ok' => true, 'id' => null])
                ->header('Cache-Control', 'no-store');
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

        $result = $this->createPublicBooking([
            'name' => $values['name'],
            'phoneE164' => $phone,
            'preferredDate' => $values['preferredDate'],
            'timeWindow' => $values['preferredTimeWindow'],
            'serviceInterest' => $values['serviceInterest'],
            'message' => $values['message'],
            'language' => $values['language'],
            'ipHash' => Ip::hashClientIp($ip),
        ]);

        if (isset($result['error'])) {
            $error = $result['error'];

            // Date/window problems are the visitor's to fix; show them inline.
            if ($error === 'invalidDate' || $error === 'pastSlot') {
                return response()->json([
                    'ok' => false,
                    'error' => 'validationError',
                    'fields' => ['preferredDate' => $error],
                ], 422);
            }

            $status = $error === 'noDoctorAvailable' ? 503 : 500;

            return response()->json(['ok' => false, 'error' => $error], $status);
        }

        return response()->json([
            'ok' => true,
            'id' => $result['id'],
            'appointment' => $result['appointment'],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Auto-approves a public booking: the visitor is matched (or created) as
     * a patient and a CONFIRMED appointment is inserted straight away.
     *
     * @param  array{name: string, phoneE164: string, preferredDate: string, timeWindow: string|null, serviceInterest: string|null, message: string|null, language: string, ipHash: string}  $input
     * @return array{id: string, appointment: array{date: string, time: string, doctor: string}}|array{error: string}
     */
    private function createPublicBooking(array $input): array
    {
        try {
            $tz = Settings::timezone();
            $now = ClinicTime::nowUtc();
            $todayKey = ClinicTime::clinicDateKey($now, $tz);
            $lastBookableKey = ClinicTime::dateKeyAdd($todayKey, Constants::MAX_REQUEST_DATE_DAYS_AHEAD);

            if ($input['preferredDate'] < $todayKey || $input['preferredDate'] > $lastBookableKey) {
                return ['error' => 'invalidDate'];
            }

            $slot = Logic::slotForTimeWindow($input['timeWindow']);
            $instants = Logic::appointmentInstants(
                $input['preferredDate'],
                $slot['start'],
                Constants::DEFAULT_DURATION,
                $tz,
            );

            // "Today" with a slot that has already gone by — nothing left to approve.
            if ($instants['startsAt']->getTimestamp() <= $now->getTimestamp()) {
                return ['error' => 'pastSlot'];
            }

            $doctors = Staff::query()
                ->where('active', true)
                ->where('is_doctor', true)
                ->orderBy('full_name')
                ->get(['id', 'full_name']);

            if ($doctors->isEmpty()) {
                return ['error' => 'noDoctorAvailable'];
            }

            $doctor = $this->pickBookingDoctor($doctors, $instants, $tz);

            [$appointmentId, $serviceId] = DB::transaction(function () use ($input, $doctor, $instants): array {
                $existing = Patient::query()->where('phone_e164', $input['phoneE164'])->first();

                $patientId = $existing !== null
                    ? $existing->id
                    : Patient::create([
                        'full_name' => $input['name'],
                        'phone_e164' => $input['phoneE164'],
                        'preferred_language' => $input['language'] === 'am' ? 'am' : 'en',
                        'active' => true,
                        'created_by' => self::PUBLIC_BOOKING_ACTOR,
                    ])->id;

                $service = $input['serviceInterest'] !== null
                    ? Service::query()
                        ->where('active', true)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($input['serviceInterest'])])
                        ->first()
                    : null;

                $appointment = Appointment::create([
                    'patient_id' => $patientId,
                    'doctor_id' => $doctor['id'],
                    'starts_at' => $instants['startsAt'],
                    'ends_at' => $instants['endsAt'],
                    'service_id' => $service?->id,
                    'status' => Appointment::STATUS_CONFIRMED,
                    'created_from' => Appointment::SOURCE_WEBSITE,
                    'created_by' => self::PUBLIC_BOOKING_ACTOR,
                    'notes' => $this->bookingNotes($input['message'], $input['serviceInterest'], $service?->id),
                ]);

                return [$appointment->id, $service?->id];
            });

            Audit::write('Appointment', $appointmentId, 'APPOINTMENT_CREATED', null, array_merge([
                'source' => 'WEBSITE',
                'status' => 'CONFIRMED',
                'doctorId' => $doctor['id'],
                'startsAt' => $instants['startsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'endsAt' => $instants['endsAt']->format('Y-m-d\TH:i:s.v\Z'),
                'serviceId' => $serviceId,
                'ipHash' => $input['ipHash'],
            ], $doctor['assignedDespiteClash'] ? ['assignedDespiteClash' => true] : []), null, null);

            return [
                'id' => $appointmentId,
                'appointment' => [
                    'date' => $input['preferredDate'],
                    'time' => $slot['start'],
                    'doctor' => $doctor['fullName'],
                ],
            ];
        } catch (\Throwable $e) {
            // The booking failed for an unexpected reason: log it, but never
            // leak the internals to the visitor.
            report($e);

            return ['error' => 'unknownError'];
        }
    }

    /**
     * Website bookings never name a doctor, so one is assigned here: an active
     * doctor with nothing overlapping the slot, the least busy of that day
     * when several are free, or any active doctor when every slot is taken.
     *
     * @param  Collection<int, Staff>  $doctors
     * @return array{id: string, fullName: string, assignedDespiteClash: bool}
     */
    private function pickBookingDoctor($doctors, array $instants, string $tz): array
    {
        $ids = $doctors->pluck('id')->all();
        $dayKey = ClinicTime::clinicDateKey($instants['startsAt'], $tz);
        $dayFrom = ClinicTime::combineDateAndTime($dayKey, '00:00', $tz);
        $dayTo = ClinicTime::combineDateAndTime(ClinicTime::dateKeyAdd($dayKey, 1), '00:00', $tz);

        $busy = Appointment::query()
            ->whereIn('doctor_id', $ids)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '<', $instants['endsAt'])
            ->where('ends_at', '>', $instants['startsAt'])
            ->take(500)
            ->pluck('doctor_id')
            ->all();

        $loads = Appointment::query()
            ->whereIn('doctor_id', $ids)
            ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', $dayFrom)
            ->where('starts_at', '<', $dayTo)
            ->get(['doctor_id'])
            ->groupBy('doctor_id')
            ->map(fn ($group): int => $group->count());

        $free = $doctors->filter(fn (Staff $doctor): bool => ! in_array($doctor->id, $busy, true))->values();
        $pool = $free->isNotEmpty() ? $free : $doctors;

        // The least busy doctor of the pool (ties keep the first, which is the
        // name-ordered one).
        $chosen = $pool->first();
        foreach ($pool as $doctor) {
            $chosenLoad = (int) ($loads[$chosen->id] ?? 0);
            $doctorLoad = (int) ($loads[$doctor->id] ?? 0);

            if ($doctorLoad < $chosenLoad) {
                $chosen = $doctor;
            }
        }

        return [
            'id' => $chosen->id,
            'fullName' => $chosen->full_name,
            'assignedDespiteClash' => $free->isEmpty(),
        ];
    }

    /** Non-clinical booking text kept on the appointment for the front desk. */
    private function bookingNotes(?string $message, ?string $serviceInterest, ?string $serviceId): ?string
    {
        $parts = [];

        if ($message !== null && $message !== '') {
            $parts[] = $message;
        }

        if ($serviceInterest !== null && $serviceInterest !== '' && $serviceId === null) {
            $parts[] = 'Service of interest: '.$serviceInterest;
        }

        $notes = implode("\n", $parts);

        return $notes === '' ? null : mb_substr($notes, 0, Constants::MAX_APPT_NOTES);
    }
}
