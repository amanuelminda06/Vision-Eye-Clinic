<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Support\Audit;
use App\Support\ClinicTime;
use App\Support\Sales\SaleQueries;
use App\Support\Sales\SaleValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Void flow, ported from the void actions in src/modules/sales/actions.ts.
 *
 * Rules preserved:
 * - Only CONFIRMED sales can be voided (`notConfirmed` otherwise).
 * - A direct void is OWNER-only; a request can come from OWNER or RECEPTION.
 * - At most one pending request (`alreadyRequested`); approve/reject need one
 *   (`noRequest`).
 * - Approve reuses the requester's reason and sets `voidedBy` to the approver.
 * - Reject clears the request fields and stores the reject text only in the
 *   audit.
 */
class SaleVoidController extends Controller
{
    /** GET /sales/voids — the OWNER void inbox. */
    public function index(): Response
    {
        return response()->view('sales.voids', [
            'rows' => SaleQueries::listVoidRequests(),
        ]);
    }

    /** POST /sales/{id}/void-request — RECEPTION (or OWNER) asks to void. */
    public function request(Request $request, string $id): RedirectResponse
    {
        $sale = Sale::find($id);

        if ($sale === null) {
            abort(404);
        }

        $validated = SaleValidation::voidReason($request->all());
        if ($validated['fields'] !== []) {
            return $this->backToSale($id)->withErrors($validated['fields']);
        }

        if ($sale->status !== Sale::STATUS_CONFIRMED) {
            return $this->backToSale($id)->with('actionError', 'notConfirmed');
        }

        if ($sale->void_requested_by !== null) {
            return $this->backToSale($id)->with('actionError', 'alreadyRequested');
        }

        $actor = $request->user();
        $sale->forceFill([
            'void_requested_by' => $actor->id,
            'void_request_reason' => $validated['value'],
        ])->save();

        Audit::write('Sale', $id, 'SALE_VOID_REQUESTED', null, [
            'voidRequestedBy' => $actor->id,
            'voidRequestReason' => $validated['value'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return $this->backToSale($id);
    }

    /** POST /sales/{id}/void — OWNER voids a confirmed sale directly. */
    public function void(Request $request, string $id): RedirectResponse
    {
        $sale = Sale::find($id);

        if ($sale === null) {
            abort(404);
        }

        $validated = SaleValidation::voidReason($request->all());
        if ($validated['fields'] !== []) {
            return $this->backToSale($id)->withErrors($validated['fields']);
        }

        if ($sale->status !== Sale::STATUS_CONFIRMED) {
            return $this->backToSale($id)->with('actionError', 'notConfirmed');
        }

        $actor = $request->user();
        $sale->forceFill([
            'status' => Sale::STATUS_VOIDED,
            'voided_by' => $actor->id,
            'void_reason' => $validated['value'],
            'voided_at' => ClinicTime::nowUtc(),
        ])->save();

        Audit::write('Sale', $id, 'SALE_VOIDED',
            ['status' => 'CONFIRMED'],
            ['status' => 'VOIDED', 'voidedBy' => $actor->id, 'voidReason' => $validated['value']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return redirect('/sales');
    }

    /** POST /sales/{id}/void-approve — OWNER approves (requester's reason). */
    public function approve(Request $request, string $id): RedirectResponse
    {
        $sale = Sale::find($id);

        if ($sale === null) {
            abort(404);
        }

        if ($sale->status !== Sale::STATUS_CONFIRMED) {
            return $this->backToSale($id)->with('actionError', 'notConfirmed');
        }

        if ($sale->void_requested_by === null) {
            return $this->backToSale($id)->with('actionError', 'noRequest');
        }

        $actor = $request->user();
        $requester = $sale->void_requested_by;
        $reason = $sale->void_request_reason;

        $sale->forceFill([
            'status' => Sale::STATUS_VOIDED,
            'voided_by' => $actor->id,
            'void_reason' => $reason,
            'voided_at' => ClinicTime::nowUtc(),
        ])->save();

        Audit::write('Sale', $id, 'SALE_VOID_APPROVED',
            ['status' => 'CONFIRMED', 'voidRequestedBy' => $requester],
            ['status' => 'VOIDED', 'voidedBy' => $actor->id, 'voidReason' => $reason],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return redirect('/sales/voids');
    }

    /** POST /sales/{id}/void-reject — OWNER rejects; sale stays confirmed. */
    public function reject(Request $request, string $id): RedirectResponse
    {
        $sale = Sale::find($id);

        if ($sale === null) {
            abort(404);
        }

        $validated = SaleValidation::voidReason($request->all());
        if ($validated['fields'] !== []) {
            return $this->backToSale($id)->withErrors($validated['fields']);
        }

        if ($sale->status !== Sale::STATUS_CONFIRMED) {
            return $this->backToSale($id)->with('actionError', 'notConfirmed');
        }

        if ($sale->void_requested_by === null) {
            return $this->backToSale($id)->with('actionError', 'noRequest');
        }

        $requester = $sale->void_requested_by;
        $requestReason = $sale->void_request_reason;

        $sale->forceFill([
            'void_requested_by' => null,
            'void_request_reason' => null,
        ])->save();

        $actor = $request->user();
        Audit::write('Sale', $id, 'SALE_VOID_REQUEST_REJECTED',
            ['voidRequestedBy' => $requester, 'voidRequestReason' => $requestReason],
            ['rejectReason' => $validated['value']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return $this->backToSale($id);
    }

    private function backToSale(string $id): RedirectResponse
    {
        return redirect("/sales/{$id}");
    }
}
