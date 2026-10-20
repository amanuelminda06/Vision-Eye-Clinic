@extends('layouts.app')

@section('title', __('sales.receiptTitle'))

@php
    use App\Support\ClinicTime;
    use App\Support\Sales\Constants;
    use App\Support\Services\Display;

    $locale = app()->getLocale();
    $payLabel = __('sales.'.Constants::paymentLabelKey($sale['paymentMethod']));
    $statusLabel = __('sales.'.Constants::statusLabelKey($sale['status']));

    $statusClass = match ($sale['status']) {
        'CONFIRMED' => 'bg-emerald-100 text-emerald-700',
        'VOIDED' => 'bg-red-100 text-red-700',
        default => 'bg-slate-100 text-slate-600',
    };

    $auditLabel = function (string $action): string {
        return match ($action) {
            'SALE_CREATED' => __('sales.auditCreated'),
            'SALE_VOID_REQUESTED' => __('sales.auditVoidRequested'),
            'SALE_VOID_APPROVED' => __('sales.auditVoidApproved'),
            'SALE_VOID_REQUEST_REJECTED' => __('sales.auditVoidRejected'),
            'SALE_VOIDED' => __('sales.auditVoided'),
            default => $action,
        };
    };
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="no-print flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('sales.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="print-a5" class="btn-primary">{{ __('sales.printA5') }}</button>
                <button type="button" id="print-thermal" class="btn-secondary">{{ __('sales.printThermal') }}</button>
            </div>
        </div>

        @if (session('actionError'))
            <div role="alert" class="no-print rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('sales.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="no-print rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- Receipt --}}
        <div id="receipt" data-print-mode="a5" class="mx-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 text-slate-900">
            <header class="text-center">
                <h2 class="text-lg font-semibold">{{ $clinicName }}</h2>
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('sales.receiptTitle') }}</p>
            </header>

            <dl class="mt-4 grid grid-cols-2 gap-y-1 text-sm">
                <dt class="text-slate-500">{{ __('sales.receiptNumber') }}</dt>
                <dd class="text-right font-medium tabular-nums">{{ $sale['receiptNumber'] }}</dd>

                <dt class="text-slate-500">{{ __('sales.date') }}</dt>
                <dd class="text-right">{{ ClinicTime::formatInTz($sale['saleDate'], $timezone) }}</dd>

                <dt class="text-slate-500">{{ __('sales.patient') }}</dt>
                <dd class="text-right font-medium">{{ $sale['patient']['fullName'] ?? __('sales.walkIn') }}</dd>

                @if ($sale['doctor'])
                    <dt class="text-slate-500">{{ __('sales.doctor') }}</dt>
                    <dd class="text-right">{{ $sale['doctor']['fullName'] }}</dd>
                @endif

                <dt class="text-slate-500">{{ __('sales.paymentMethod') }}</dt>
                <dd class="text-right">{{ $payLabel }}</dd>
            </dl>

            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                        <th class="py-1 pr-2 text-left font-medium">{{ __('sales.service') }}</th>
                        <th class="px-2 py-1 text-right font-medium">{{ __('sales.qty') }}</th>
                        <th class="py-1 pl-2 text-right font-medium">{{ __('sales.lineTotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sale['lines'] as $line)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-1 pr-2">
                                {{ Display::receiptLabelIn($locale, [
                                    'receiptLabelEn' => $line['receiptLabelEn'],
                                    'receiptLabelAm' => $line['receiptLabelAm'],
                                    'fallback' => $line['serviceNameSnapshot'],
                                ]) }}
                            </td>
                            <td class="px-2 py-1 text-right">{{ $line['qty'] }}</td>
                            <td class="py-1 pl-2 text-right tabular-nums">{{ format_money($line['lineTotal'], $currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                <span>{{ __('sales.total') }}</span>
                <span class="tabular-nums">{{ format_money($sale['total'], $currency) }}</span>
            </div>

            @if ($sale['status'] === 'VOIDED')
                <p class="mt-3 rounded bg-red-50 px-2 py-1 text-center text-xs font-semibold text-red-700">{{ __('sales.voidedWatermark') }}</p>
            @endif

            <p class="mt-4 text-center text-xs text-slate-500">{{ __('sales.receiptFooter') }}</p>
        </div>

        {{-- Pending void request notice --}}
        @if ($sale['voidRequestedBy'])
            <div class="no-print rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                <p class="font-medium">{{ __('sales.voidRequestPending') }}</p>
                <p class="text-xs">{{ __t('sales.voidRequestedBy', ['name' => $sale['voidRequestedByName'] ?? '—']) }} — {{ $sale['voidRequestReason'] }}</p>
            </div>
        @endif

        @if ($sale['status'] === 'VOIDED')
            <div class="no-print rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __t('sales.voidedMeta', ['name' => $sale['voidedByName'] ?? '—', 'date' => $sale['voidedAt']?->toDateTimeString() ?? '—']) }}
                — {{ $sale['voidReason'] }}
            </div>
        @endif

        {{-- Actions --}}
        <section class="no-print card space-y-3 p-4">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('common.actions') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                @if ($sale['status'] === 'CONFIRMED' && $canVoidDirectly)
                    <button type="button" class="btn-danger" data-open-modal="void">{{ __('sales.voidSale') }}</button>
                @endif
                @if ($sale['status'] === 'CONFIRMED' && ! $canVoidDirectly && $canRequestVoid && ! $sale['voidRequestedBy'])
                    <button type="button" class="btn-secondary" data-open-modal="request">{{ __('sales.requestVoid') }}</button>
                @endif
                @if ($sale['status'] === 'CONFIRMED' && $canApproveVoid && $sale['voidRequestedBy'])
                    <form method="POST" action="{{ route('sales.void-approve', $sale['id']) }}">
                        @csrf
                        <button type="submit" class="btn-primary">{{ __('sales.approveVoid') }}</button>
                    </form>
                    <button type="button" class="btn-secondary" data-open-modal="reject">{{ __('sales.rejectVoid') }}</button>
                @endif
                @if ($sale['status'] !== 'CONFIRMED' || (! $canVoidDirectly && (! $canRequestVoid || $sale['voidRequestedBy'])))
                    <p class="text-sm text-slate-500">{{ __('sales.noActions') }}</p>
                @endif
            </div>
        </section>

        {{-- Audit trail --}}
        <section class="no-print card p-4">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('sales.auditTrail') }}</h2>
            <ul class="mt-3 space-y-1 text-sm">
                @forelse ($audit as $entry)
                    <li class="flex items-center justify-between border-b border-brand-50 py-1 last:border-0">
                        <span>{{ $auditLabel($entry['action']) }}</span>
                        <span class="text-xs text-slate-500">{{ $entry['userName'] ?? '—' }} · {{ $entry['createdAt']?->toDateTimeString() }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">{{ __('common.empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Void modal (direct) --}}
    @if ($sale['status'] === 'CONFIRMED' && $canVoidDirectly)
        <div id="modal-void" class="no-print fixed inset-0 z-50 hidden items-center justify-center p-4">
            <button type="button" class="absolute inset-0 bg-slate-900/40" data-close-modal aria-label="{{ __('common.close') }}"></button>
            <form method="POST" action="{{ route('sales.void', $sale['id']) }}" class="relative z-10 w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                @csrf
                <h2 class="text-sm font-semibold text-slate-900">{{ __('sales.voidSaleTitle') }}</h2>
                <p class="text-sm text-slate-600">{{ __('sales.voidSaleBody') }}</p>
                <div>
                    <label class="label" for="void-reason">{{ __('sales.reason') }}</label>
                    <textarea class="input-field min-h-20" id="void-reason" name="reason" required maxlength="{{ \App\Support\Sales\Constants::MAX_REASON }}"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" data-close-modal>{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn-danger">{{ __('sales.voidSale') }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Request void modal --}}
    @if ($sale['status'] === 'CONFIRMED' && ! $canVoidDirectly && $canRequestVoid && ! $sale['voidRequestedBy'])
        <div id="modal-request" class="no-print fixed inset-0 z-50 hidden items-center justify-center p-4">
            <button type="button" class="absolute inset-0 bg-slate-900/40" data-close-modal aria-label="{{ __('common.close') }}"></button>
            <form method="POST" action="{{ route('sales.void-request', $sale['id']) }}" class="relative z-10 w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                @csrf
                <h2 class="text-sm font-semibold text-slate-900">{{ __('sales.requestVoidTitle') }}</h2>
                <p class="text-sm text-slate-600">{{ __('sales.requestVoidBody') }}</p>
                <div>
                    <label class="label" for="request-reason">{{ __('sales.reason') }}</label>
                    <textarea class="input-field min-h-20" id="request-reason" name="reason" required maxlength="{{ \App\Support\Sales\Constants::MAX_REASON }}"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" data-close-modal>{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn-primary">{{ __('sales.requestVoid') }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Reject modal --}}
    @if ($sale['status'] === 'CONFIRMED' && $canApproveVoid && $sale['voidRequestedBy'])
        <div id="modal-reject" class="no-print fixed inset-0 z-50 hidden items-center justify-center p-4">
            <button type="button" class="absolute inset-0 bg-slate-900/40" data-close-modal aria-label="{{ __('common.close') }}"></button>
            <form method="POST" action="{{ route('sales.void-reject', $sale['id']) }}" class="relative z-10 w-full max-w-md space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                @csrf
                <h2 class="text-sm font-semibold text-slate-900">{{ __('sales.rejectVoidTitle') }}</h2>
                <div>
                    <label class="label" for="reject-reason">{{ __('sales.reason') }}</label>
                    <textarea class="input-field min-h-20" id="reject-reason" name="reason" required maxlength="{{ \App\Support\Sales\Constants::MAX_REASON }}"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" data-close-modal>{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn-danger">{{ __('sales.rejectVoid') }}</button>
                </div>
            </form>
        </div>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    var styleEl = null;
    function printMode(mode) {
        var root = document.getElementById('receipt');
        if (!root) return;
        root.dataset.printMode = mode;
        if (!styleEl) { styleEl = document.createElement('style'); document.head.appendChild(styleEl); }
        styleEl.textContent = mode === 'thermal'
            ? '@page { size: 80mm auto; margin: 3mm; }'
            : '@page { size: A5; margin: 10mm; }';
        window.print();
    }
    var a5 = document.getElementById('print-a5');
    var thermal = document.getElementById('print-thermal');
    if (a5) a5.addEventListener('click', function () { printMode('a5'); });
    if (thermal) thermal.addEventListener('click', function () { printMode('thermal'); });

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = document.getElementById('modal-' + btn.dataset.openModal);
            if (modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = btn.closest('[id^="modal-"]');
            if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
        });
    });
})();
</script>
@endpush
