@extends('layouts.app')

@section('title', __('sales.title'))

@php
    use App\Support\Sales\Constants;

    $buildQuery = function (array $patch = []) use ($filters): string {
        $merged = array_merge($filters, $patch);
        $params = [];
        foreach (['from', 'to', 'patient', 'serviceId', 'doctorId', 'status', 'paymentMethod'] as $key) {
            if (! empty($merged[$key])) $params[$key] = $merged[$key];
        }
        if (($merged['page'] ?? 1) > 1) $params['page'] = (string) $merged['page'];
        return $params === [] ? '' : '?'.http_build_query($params);
    };

    $exportQuery = $buildQuery(['page' => 1]);

    $statusLabel = fn (string $status): string => __('sales.'.Constants::statusLabelKey($status));
    $payLabel = fn (string $method): string => __('sales.'.Constants::paymentLabelKey($method));

    $statusClass = fn (string $status): string => match ($status) {
        'CONFIRMED' => 'bg-emerald-100 text-emerald-700',
        'VOIDED' => 'bg-red-100 text-red-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('sales.title') }}</h1>
            <div class="flex items-center gap-2">
                @if ($canViewRevenue)
                    <a href="{{ route('sales.voids') }}" class="btn-secondary">{{ __('sales.voidRequests') }}</a>
                @endif
                <a href="{{ route('sales.create') }}" class="btn-primary">{{ __('sales.newSale') }}</a>
            </div>
        </div>

        <form method="get" action="{{ route('sales.index') }}" class="card grid grid-cols-1 gap-2 p-3 sm:grid-cols-3 lg:grid-cols-6">
            <div>
                <label class="label text-xs" for="from">{{ __('sales.from') }}</label>
                <input class="input-field text-sm" id="from" name="from" type="date" value="{{ $filters['from'] }}">
            </div>
            <div>
                <label class="label text-xs" for="to">{{ __('sales.to') }}</label>
                <input class="input-field text-sm" id="to" name="to" type="date" value="{{ $filters['to'] }}">
            </div>
            <div class="sm:col-span-2">
                <label class="label text-xs" for="patient">{{ __('sales.patient') }}</label>
                <input class="input-field text-sm" id="patient" name="patient" type="search" maxlength="{{ Constants::MAX_PATIENT_QUERY }}"
                       value="{{ $filters['patient'] }}" placeholder="{{ __('sales.patientFilterPlaceholder') }}">
            </div>
            <div>
                <label class="label text-xs" for="status">{{ __('sales.status') }}</label>
                <select class="input-field text-sm" id="status" name="status">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach (Constants::SALE_STATUSES as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('sales.'.Constants::statusLabelKey($status)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label text-xs" for="paymentMethod">{{ __('sales.paymentMethod') }}</label>
                <select class="input-field text-sm" id="paymentMethod" name="paymentMethod">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach (Constants::PAYMENT_METHODS as $method)
                        <option value="{{ $method }}" @selected($filters['paymentMethod'] === $method)>{{ __('sales.'.Constants::paymentLabelKey($method)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2 sm:col-span-3 lg:col-span-6">
                <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
                <a href="{{ route('sales.index') }}" class="pb-1 text-xs text-slate-500 hover:text-slate-800">{{ __('common.clear') }}</a>
                <div class="ml-auto">
                    @include('partials.export-links', ['base' => route('sales.export'), 'query' => $exportQuery])
                </div>
            </div>
        </form>

        <div class="flex items-center justify-between text-sm text-slate-600">
            <span>{{ __('common.showing', ['from' => $rows === [] ? 0 : (($page - 1) * Constants::SALES_PAGE_SIZE) + 1, 'to' => (($page - 1) * Constants::SALES_PAGE_SIZE) + count($rows), 'total' => $total]) }}</span>
            <span class="font-medium tabular-nums">{{ __t('sales.sumTotal', ['total' => format_money($sumTotal)]) }}</span>
        </div>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('sales.receiptNumber') }}</th>
                        <th class="px-3 py-2">{{ __('sales.date') }}</th>
                        <th class="px-3 py-2">{{ __('sales.patient') }}</th>
                        <th class="px-3 py-2">{{ __('sales.doctor') }}</th>
                        <th class="px-3 py-2">{{ __('sales.paymentMethod') }}</th>
                        <th class="px-3 py-2">{{ __('sales.status') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('sales.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <a href="{{ route('sales.show', $row['id']) }}" class="font-medium text-brand-800 hover:underline tabular-nums">{{ $row['receiptNumber'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['saleDate']?->toDateTimeString() }}</td>
                            <td class="px-3 py-2">{{ $row['patient']['fullName'] ?? __('sales.walkIn') }}</td>
                            <td class="px-3 py-2">{{ $row['doctor']['fullName'] ?? __('sales.noDoctor') }}</td>
                            <td class="px-3 py-2">{{ $payLabel($row['paymentMethod']) }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ format_money($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($pageCount > 1)
            <nav class="flex items-center justify-center gap-3 text-sm" aria-label="pagination">
                @if ($page > 1)
                    <a href="{{ route('sales.index').$buildQuery(['page' => $page - 1]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.previous') }}</a>
                @endif
                <span class="text-slate-500">{{ __('common.page') }} {{ $page }} {{ __('common.of') }} {{ $pageCount }}</span>
                @if ($page < $pageCount)
                    <a href="{{ route('sales.index').$buildQuery(['page' => $page + 1]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.next') }}</a>
                @endif
            </nav>
        @endif
    </div>
@endsection
