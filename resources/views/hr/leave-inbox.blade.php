@extends('layouts.app')

@section('title', __('hr.leaveInboxTitle'))

@php
    use App\Support\Hr\Constants;
    use App\Support\Hr\Shifts;

    $typeLabel = fn (string $type): string => __('hr.'.Constants::LEAVE_TYPE_LABEL_KEYS[$type] ?? $type);
@endphp

@section('content')
    <div class="space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('hr.leaveInboxTitle') }}</h1>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('hr.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        @if ($rows === [])
            <p class="text-sm text-slate-500">{{ __('hr.inboxEmpty') }}</p>
        @else
            <div class="space-y-4">
                @foreach ($rows as $row)
                    <div class="card space-y-3 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $row['staffName'] }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $typeLabel($row['type']) }} ·
                                    {{ Shifts::dateColumnToKey($row['startDate']) }} → {{ Shifts::dateColumnToKey($row['endDate']) }}
                                    @if ($row['halfDay'])
                                        · {{ __('hr.halfDay') }}
                                    @endif
                                </p>
                            </div>
                            @if ($row['appointmentConflicts'] > 0)
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                    {{ __t('hr.conflictAppointments', ['count' => $row['appointmentConflicts']]) }}
                                </span>
                            @endif
                        </div>

                        @if ($row['reason'])
                            <p class="text-sm text-slate-700">{{ $row['reason'] }}</p>
                        @endif

                        <div class="flex flex-wrap items-end gap-3">
                            <form method="POST" action="{{ route('leave.approve', $row['id']) }}" class="flex items-end gap-2">
                                @csrf
                                <input type="hidden" name="leaveId" value="{{ $row['id'] }}">
                                <div>
                                    <label class="label text-xs" for="approve-note-{{ $row['id'] }}">{{ __('hr.decisionNote') }}</label>
                                    <input class="input-field text-sm" id="approve-note-{{ $row['id'] }}" name="note" maxlength="300">
                                </div>
                                <button type="submit" class="btn-primary">{{ __('hr.approve') }}</button>
                            </form>

                            <form method="POST" action="{{ route('leave.reject', $row['id']) }}" class="flex items-end gap-2">
                                @csrf
                                <input type="hidden" name="leaveId" value="{{ $row['id'] }}">
                                <div>
                                    <label class="label text-xs" for="reject-note-{{ $row['id'] }}">{{ __('hr.decisionNote') }}</label>
                                    <input class="input-field text-sm" id="reject-note-{{ $row['id'] }}" name="note" maxlength="300">
                                </div>
                                <button type="submit" class="btn-secondary">{{ __('hr.reject') }}</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
