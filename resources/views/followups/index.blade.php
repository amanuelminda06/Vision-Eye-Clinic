@extends('layouts.app')

@section('title', __('followups.title'))

@php
    use App\Support\ClinicTime;

    $statusLabel = fn (string $status): string => match ($status) {
        'OPEN' => __('followups.statusOpen'),
        'DONE' => __('followups.statusDone'),
        default => __('followups.statusCancelled'),
    };

    $statusClass = fn (string $status): string => match ($status) {
        'OPEN' => 'bg-emerald-50 text-emerald-700',
        'DONE' => 'bg-slate-100 text-slate-600',
        default => 'bg-red-50 text-red-700',
    };

    $isOverdue = fn (array $row): bool => $row['status'] === 'OPEN'
        && $row['dueDate'] !== null
        && ClinicTime::clinicDateKey($row['dueDate'], $timezone) < $todayKey;
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('followups.title') }}</h1>
            <a href="{{ route('followups.create') }}" class="btn-primary">{{ __('appointments.createFollowUp') }}</a>
        </div>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('appointments.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- Tabs --}}
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['open' => 'tabOpen', 'overdue' => 'tabOverdue', 'done' => 'tabDone', 'cancelled' => 'tabCancelled'] as $key => $labelKey)
                <a href="{{ route('followups.index', ['status' => $key, 'patient' => $patientId]) }}"
                   class="{{ $tab === $key ? 'btn-primary' : 'btn-secondary' }} text-sm">
                    {{ __('followups.'.$labelKey) }}
                    @if ($key === 'overdue' && $overdueCount > 0)
                        <span class="ml-1 rounded-full bg-red-100 px-1.5 text-xs text-red-700">{{ $overdueCount }}</span>
                    @endif
                </a>
            @endforeach

            <form method="GET" action="{{ route('followups.index') }}" class="ml-auto flex items-center gap-2">
                <input type="hidden" name="status" value="{{ $tab }}">
                <select class="input-field text-sm" name="patient" onchange="this.form.submit()">
                    <option value="">{{ __('followups.allPatients') }}</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" @selected($patientId === $patient->id)>{{ $patient->full_name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('followups.patient') }}</th>
                        <th class="px-3 py-2">{{ __('followups.dueDate') }}</th>
                        <th class="px-3 py-2">{{ __('followups.reason') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <a href="{{ route('patients.show', $row['patientId']) }}" class="text-brand-700 hover:underline">{{ $row['patientName'] }}</a>
                            </td>
                            <td class="px-3 py-2 {{ $isOverdue($row) ? 'font-bold text-red-600' : '' }}">
                                {{ $row['dueDate']?->format('Y-m-d') }}
                                @if ($isOverdue($row))
                                    <span class="ml-1 rounded-full bg-red-50 px-1.5 py-0.5 text-xs text-red-700">{{ __('followups.overdueTag') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $row['reason'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                            </td>
                            <td class="px-3 py-2">
                                @if ($row['status'] === 'OPEN')
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route('followups.status', $row['id']) }}">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="status" value="DONE">
                                            <button type="submit" class="text-xs text-brand-700 hover:underline">{{ __('followups.markDone') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('followups.status', $row['id']) }}" onsubmit="return confirm('{{ __('followups.cancelConfirm') }}')">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="status" value="CANCELLED">
                                            <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('followups.cancelFollowUp') }}</button>
                                        </form>
                                    </div>
                                @endif
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
