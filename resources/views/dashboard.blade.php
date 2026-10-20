@extends('layouts.app')

@section('title', __('dashboard.title'))

@php
    use App\Support\ClinicTime;
    use App\Support\Hr\PublicStatus;

    $statusLabel = fn (string $status): string => match ($status) {
        PublicStatus::ON_DUTY_NOW => __('hr.onDutyNow'),
        PublicStatus::ON_LEAVE => __('hr.onLeave'),
        default => __('hr.notOnDuty'),
    };

    $statusClass = fn (string $status): string => match ($status) {
        PublicStatus::ON_DUTY_NOW => 'bg-emerald-50 text-emerald-700',
        PublicStatus::ON_LEAVE => 'bg-amber-50 text-amber-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

@section('content')
    <h1 class="text-xl font-semibold text-slate-900">{{ __('dashboard.title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">
        {{ __t('dashboard.subtitle', [
            'name' => $user->name,
            'role' => __('common.role'.ucfirst(strtolower($user->role))),
        ]) }}
    </p>

    {{-- Who is on duty now (all doctors, including unpublished ones). --}}
    <section class="card mt-6 p-5">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.onDutyTitle') }}</h2>
            <span class="text-xs text-slate-500">{{ $todayKey }}</span>
        </div>

        @if (count($onDuty) === 0)
            <p class="mt-3 text-sm text-slate-500">{{ __('hr.noShiftsToday') }}</p>
        @else
            <ul class="mt-3 divide-y divide-brand-50">
                @foreach ($onDuty as $doctor)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="flex min-w-0 items-center gap-3">
                            @if ($doctor['photoUrl'])
                                <img src="{{ $doctor['photoUrl'] }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($doctor['name'], 0, 1)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $doctor['name'] }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    @if (count($doctor['todayShifts']) === 0)
                                        {{ __('hr.noShiftsToday') }}
                                    @else
                                        @foreach ($doctor['todayShifts'] as $shift)
                                            {{ $shift['start'] }}–{{ $shift['end'] }}@if (! $loop->last), @endif
                                        @endforeach
                                    @endif
                                </p>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($doctor['status']) }}">
                            {{ $statusLabel($doctor['status']) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
