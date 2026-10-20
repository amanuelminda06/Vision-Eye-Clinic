@extends('layouts.app')

@section('title', __('sales.voidRequests'))

@php
    use App\Support\Sales\Constants;
@endphp

@section('content')
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('sales.voidRequests') }}</h1>
            <a href="{{ route('sales.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('sales.receiptNumber') }}</th>
                        <th class="px-3 py-2">{{ __('sales.date') }}</th>
                        <th class="px-3 py-2">{{ __('sales.patient') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('sales.total') }}</th>
                        <th class="px-3 py-2">{{ __('sales.requestedBy') }}</th>
                        <th class="px-3 py-2">{{ __('sales.reason') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <a href="{{ route('sales.show', $row['id']) }}" class="font-medium text-brand-800 hover:underline tabular-nums">{{ $row['receiptNumber'] }}</a>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['saleDate']?->toDateTimeString() }}</td>
                            <td class="px-3 py-2">{{ $row['patientName'] ?? __('sales.walkIn') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ format_money($row['total']) }}</td>
                            <td class="px-3 py-2">{{ $row['requestedByName'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ $row['reason'] }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('sales.void-approve', $row['id']) }}">
                                        @csrf
                                        <button type="submit" class="btn-primary px-2 py-1 text-xs">{{ __('sales.approveVoid') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('sales.void-reject', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="reason" value="Request rejected by the owner">
                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('sales.rejectVoid') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
