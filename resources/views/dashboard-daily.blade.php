@extends('layouts.app')

@section('title', __('nav.dailySummary'))

@php
    use App\Support\Sales\Constants;
@endphp

@section('content')
    <div class="space-y-5">
        <div class="no-print flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('nav.dailySummary') }}</h1>
            <div class="flex items-center gap-2">
                <a href="{{ route('dashboard.daily', ['day' => $prevKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.previous') }}</a>
                <a href="{{ route('dashboard.daily', ['day' => $todayKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('appointments.today') }}</a>
                <a href="{{ route('dashboard.daily', ['day' => $nextKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.next') }}</a>
                <button type="button" class="btn-secondary" onclick="window.print()">{{ __('common.print') }}</button>
            </div>
        </div>

        <div id="print-daily" class="card space-y-4 p-5">
            <p class="text-sm text-slate-500">{{ $dayKey }}</p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('sales.statusConfirmed') }}</p>
                    <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ format_money($confirmedTotal, $currency) }}</p>
                    <p class="text-xs text-slate-500">{{ $confirmedCount }} {{ __('sales.statusConfirmed') }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('sales.statusVoided') }}</p>
                    <p class="text-2xl font-semibold text-red-700 tabular-nums">{{ format_money($voidedTotal, $currency) }}</p>
                    <p class="text-xs text-slate-500">{{ $voidedCount }} {{ __('sales.statusVoided') }}</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium">{{ __('sales.receiptNumber') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('appointments.when') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('appointments.patient') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('appointments.doctor') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('common.status') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('sales.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $row)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="py-2 pr-3 font-medium text-slate-900 tabular-nums">{{ $row['receiptNumber'] }}</td>
                                <td class="py-2 pr-3 text-xs text-slate-600">{{ $row['time'] }}</td>
                                <td class="py-2 pr-3">{{ $row['patientName'] }}</td>
                                <td class="py-2 pr-3">{{ $row['doctorName'] }}</td>
                                <td class="py-2 pr-3 text-xs">{{ __('sales.'.(Constants::STATUS_LABEL_KEYS[$row['status']] ?? $row['status'])) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ format_money($row['total'], $currency) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
