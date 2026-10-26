@extends('layouts.app')

@section('title', __('appointments.createFollowUp'))

@php
    use App\Support\Appointments\Constants;
    use App\Support\ClinicTime;

    $old = fn (string $key, $default = '') => old($key, $default);
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
    $todayKey = ClinicTime::clinicDateKey(now(), $timezone);
@endphp

@section('content')
    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('appointments.createFollowUp') }}</h1>
            <a href="{{ route('followups.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ route('followups.store') }}" class="card space-y-4 p-5">
            @csrf

            <input type="hidden" name="patientId" value="{{ $old('patientId', $patientId ?? '') }}">
            <input type="hidden" name="appointmentId" value="{{ $old('appointmentId', $appointmentId ?? '') }}">

            <div>
                <label class="label" for="preset">{{ __('appointments.presetLabel') }}</label>
                <select class="input-field" id="preset" name="preset">
                    @foreach (Constants::FOLLOW_UP_PRESETS as $preset)
                        <option value="{{ $preset }}" @selected($old('preset', '2w') === $preset)>{{ __('appointments.preset.'.$preset) }}</option>
                    @endforeach
                </select>
                @error('preset')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label" for="dueDate">{{ __('appointments.dueDate') }}</label>
                <input class="input-field" id="dueDate" name="dueDate" type="date" value="{{ $old('dueDate', $todayKey) }}">
                @error('dueDate')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label" for="reason">{{ __('appointments.reason') }}</label>
                <textarea class="input-field text-sm" id="reason" name="reason" rows="2" maxlength="300" placeholder="{{ __('appointments.reasonHint') }}">{{ $old('reason') }}</textarea>
                @error('reason')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
                <a href="{{ route('followups.index') }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
