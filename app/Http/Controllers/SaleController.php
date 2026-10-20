<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Staff;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Money;
use App\Support\Roles;
use App\Support\Sales\Constants;
use App\Support\Sales\Lines;
use App\Support\Sales\Receipt;
use App\Support\Sales\SaleQueries;
use App\Support\Sales\SaleValidation;
use App\Support\Services\ServiceQueries;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales front desk: the list, the new-sale screen and the immutable detail
 * page, ported from src/modules/sales/actions.ts + queries.ts + the pages.
 *
 * Rules preserved:
 * - SALES_ROLES = OWNER + RECEPTION; STAFF never reaches here (route gate).
 * - The client only sends `serviceId` + `qty`; prices are recomputed from DB
 *   rows (a tampered price cannot be injected).
 * - Sales are created directly as CONFIRMED with a gap-free receipt number
 *   claimed inside the same transaction.
 * - RECEPTION sees only their own sales of the current clinic day.
 * - Confirmed sales are immutable: the only transition lives in
 *   SaleVoidController.
 */
class SaleController extends Controller
{
    /** GET /sales — list + filters + scoped pagination. */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        $filters = SaleQueries::scopeToActor($this->readFilters($request), $actor, $todayKey);
        $result = SaleQueries::listSales($filters, $tz);

        return response()->view('sales.index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pageCount' => $result['pageCount'],
            'sumTotal' => $result['sumTotal'],
            'filters' => $filters,
            'canViewRevenue' => Roles::canViewRevenue($actor->role),
        ]);
    }

    /** GET /sales/new — new-sale form (active services + doctor options). */
    public function create(Request $request): Response
    {
        $services = ServiceQueries::listActiveServices();
        $prefillServiceId = (string) $request->query('serviceId', '');

        return response()->view('sales.form', [
            'patient' => null,
            'services' => $services,
            'doctors' => SaleQueries::listDoctorOptions(),
            'prefillServiceId' => $prefillServiceId,
        ]);
    }

    /** POST /sales — create + confirm a sale. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $input = [
            'patientId' => (string) $request->input('patientId', ''),
            'doctorId' => (string) $request->input('doctorId', ''),
            'paymentMethod' => (string) $request->input('paymentMethod', ''),
            'walkIn' => (string) $request->input('walkIn', ''),
            'lines' => SaleValidation::parseLines($request->input('lines')),
        ];

        $validated = SaleValidation::saleForm($input);

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput($request->except('lines'))->with('linesRaw', $request->input('lines'));
        }

        $values = $validated['values'];

        $patientId = null;
        if (! $values['walkIn']) {
            if ($values['patientId'] === '') {
                return back()->withErrors(['patientId' => 'required'])->withInput();
            }

            $patient = Patient::find($values['patientId']);
            if ($patient === null || ! $patient->active) {
                return back()->withErrors(['patientId' => 'invalid'])->withInput();
            }

            $patientId = $patient->id;
        }

        $doctorId = null;
        if ($values['doctorId'] !== '') {
            $doctor = Staff::find($values['doctorId']);
            if ($doctor === null || ! $doctor->active) {
                return back()->withErrors(['doctorId' => 'invalid'])->withInput();
            }

            $doctorId = $doctor->id;
        }

        $serviceIds = array_values(array_unique(array_map(fn (array $l): string => $l['serviceId'], $values['lines'])));
        $services = Service::query()->whereIn('id', $serviceIds)->get();

        $snapshots = $services->map(fn (Service $service): array => [
            'id' => $service->id,
            'name' => $service->name,
            'currentPrice' => Money::toDecimalString($service->current_price),
            'active' => (bool) $service->active,
        ])->all();

        $computed = Lines::computeSaleLines($snapshots, $values['lines']);

        if (! $computed['ok']) {
            return back()->withErrors(['lines' => $computed['error']])->withInput();
        }

        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $sale = DB::transaction(function () use ($patientId, $doctorId, $values, $computed, $now, $tz, $actor): Sale {
            $receiptNumber = Receipt::nextReceiptNumber($now, $tz);

            $sale = Sale::create([
                'receipt_number' => $receiptNumber,
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'sale_date' => $now,
                'status' => Sale::STATUS_CONFIRMED,
                'payment_method' => $values['paymentMethod'],
                'total' => $computed['total'],
                'created_by' => $actor->id,
                'confirmed_at' => $now,
            ]);

            foreach ($computed['lines'] as $line) {
                $sale->lines()->create([
                    'service_id' => $line['serviceId'],
                    'service_name_snapshot' => $line['serviceNameSnapshot'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unitPrice'],
                    'line_total' => $line['lineTotal'],
                ]);
            }

            return $sale;
        });

        Audit::write('Sale', $sale->id, 'SALE_CREATED', null, [
            'receiptNumber' => $sale->receipt_number,
            'patientId' => $patientId,
            'doctorId' => $doctorId,
            'paymentMethod' => $sale->payment_method,
            'status' => $sale->status,
            'total' => Money::toDecimalString($sale->total),
            'lines' => array_map(fn (array $line): array => [
                'service' => $line['serviceNameSnapshot'],
                'qty' => $line['qty'],
                'unitPrice' => Money::formatMoney($line['unitPrice']),
                'lineTotal' => Money::formatMoney($line['lineTotal']),
            ], $computed['lines']),
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect("/sales/{$sale->id}?receipt=1");
    }

    /** GET /sales/{id} — receipt + audit trail + actions. */
    public function show(Request $request, string $id): Response
    {
        $actor = $request->user();
        $sale = SaleQueries::getSale($id);

        if ($sale === null) {
            abort(404);
        }

        // RECEPTION may only open their own sale.
        if (! Roles::canSeeAllSales($actor->role) && $sale['createdBy'] !== $actor->id) {
            abort(404);
        }

        return response()->view('sales.show', [
            'sale' => $sale,
            'receiptMode' => (string) $request->query('receipt', '') === '1',
            'audit' => SaleQueries::listSaleAudit($id),
            'canApproveVoid' => Roles::canApproveVoid($actor->role),
            'canVoidDirectly' => Roles::canVoidDirectly($actor->role),
            'canRequestVoid' => Roles::canRequestVoid($actor->role),
            'clinicName' => (string) Settings::get('clinic_name', ''),
            'currency' => Settings::currency(),
            'timezone' => Settings::timezone(),
        ]);
    }

    /**
     * Reads + sanitizes the list/export filters (unknown or out-of-range
     * params are dropped — §7 in the spec).
     *
     * @return array<string, mixed>
     */
    private function readFilters(Request $request): array
    {
        $dateRe = '/^\d{4}-\d{2}-\d{2}$/';

        $from = mb_substr((string) $request->query('from', ''), 0, 10);
        $from = preg_match($dateRe, $from) === 1 ? $from : '';
        $to = mb_substr((string) $request->query('to', ''), 0, 10);
        $to = preg_match($dateRe, $to) === 1 ? $to : '';

        $patient = mb_substr((string) $request->query('patient', ''), 0, Constants::MAX_PATIENT_QUERY);
        $serviceId = mb_substr((string) $request->query('serviceId', ''), 0, 64);
        $doctorId = mb_substr((string) $request->query('doctorId', ''), 0, 64);

        $statusRaw = (string) $request->query('status', '');
        $status = Constants::isSaleStatus($statusRaw) ? $statusRaw : '';
        $paymentRaw = (string) $request->query('paymentMethod', '');
        $paymentMethod = Constants::isPaymentMethod($paymentRaw) ? $paymentRaw : '';

        return [
            'from' => $from,
            'to' => $to,
            'patient' => $patient,
            'serviceId' => $serviceId,
            'doctorId' => $doctorId,
            'status' => $status,
            'paymentMethod' => $paymentMethod,
            'page' => (int) $request->query('page', 1),
        ];
    }
}
