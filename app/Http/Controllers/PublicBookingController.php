<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\Patient;
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

/**
 * Public, unauthenticated intake for the website's "book an appointment" form,
 * ported from src/app/api/public/requests/route.ts.
 *
 * A booking creates ONE row in the request queue (status NEW) — never an
 * appointment and never a patient. The front desk converts it into a real
 * appointment, contacts the visitor, declines it or marks it as spam.
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

            return response()->json(['ok' => false, 'error' => $error], 500);
        }

        return response()->json([
            'ok' => true,
            'id' => $result['id'],
        ])->header('Cache-Control', 'no-store');
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
}
