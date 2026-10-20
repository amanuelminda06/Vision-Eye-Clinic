@extends('layouts.app')

@section('title', __('hr.leaveCalendarTitle'))

@php
    use App\Support\Hr\Constants;
    use App\Support\Hr\Shifts;

    $typeLabel = fn (string $type): string => __('hr.'.Constants::LEAVE_TYPE_LABEL_KEYS[$type] ?? $type);
    $statusLabel = fn (string $status): string => __('hr.'.Constants::LEAVE_STATUS_LABEL_KEYS[$status] ?? $status);

    $statusClass = fn (string $status): string => match ($status) {
        'APPROVED' => 'bg-emerald-50 text-emerald-700',
        default => 'bg-amber-50 text-amber-700',
    };
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('hr.leaveCalendarTitle') }}</h1>
            <div class="flex items-center gap-2 text-sm">
                <a href="{{ route('leave.calendar', ['from' => $prevKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.previous') }}</a>
                <a href="{{ route('leave.calendar', ['from' => $toKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.next') }}</a>
            </div>
        </div>

        <p class="text-sm text-slate-500">{{ $fromKey }} → {{ $toKey }}</p>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('hr.staffLabel') }}</th>
                        <th class="px-3 py-2">{{ __('hr.leaveType') }}</th>
                        <th class="px-3 py-2">{{ __('hr.startDate') }}</th>
                        <th class="px-3 py-2">{{ __('hr.endDate') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">{{ $row['staffName'] }}</td>
                            <td class="px-3 py-2">{{ $typeLabel($row['type']) }}</td>
                            <td class="px-3 py-2">{{ Shifts::dateColumnToKey($row['startDate']) }}</td>
                            <td class="px-3 py-2">{{ Shifts::dateColumnToKey($row['endDate']) }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
