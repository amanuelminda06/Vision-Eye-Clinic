@extends('layouts.app')

@section('title', $appointment === null ? __('appointments.newAppointment') : __('appointments.editAppointment'))

@php
    use App\Support\Appointments\Constants;
    use App\Support\ClinicTime;

    $old = fn (string $key, $default = '') => old($key, $default);
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">
                {{ $appointment === null ? __('appointments.newAppointment') : __('appointments.editAppointment') }}
            </h1>
            <a href="{{ route('appointments.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="card space-y-4 p-5">
            @csrf

            @if ($appointment !== null)
                <input type="hidden" name="id" value="{{ $appointment->id }}">
            @endif

            {{-- Patient --}}
            <div class="space-y-2">
                <label class="label" for="patientId">{{ __('appointments.patient') }}</label>
                <input class="input-field" id="patientId" name="patientId" type="search" maxlength="64"
                       list="appointment-patients"
                       value="{{ $old('patientId', $appointment['patientId'] ?? '') }}"
                       placeholder="{{ __('appointments.patientSearchPlaceholder') }}">
                <datalist id="appointment-patients">
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}">{{ $patient->full_name }}</option>
                    @endforeach
                </datalist>
                @error('patientId')<p class="text-xs text-red-600">{{ field_error('patientId') }}</p>@enderror

                <details class="rounded-lg border border-brand-100 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-slate-700">{{ __('appointments.quickCreate') }}</summary>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="label text-xs" for="quickName">{{ __('appointments.quickName') }}</label>
                            <input class="input-field text-sm" id="quickName" name="quickName" maxlength="100" value="{{ $old('quickName') }}">
                            @error('quickName')<p class="text-xs text-red-600">{{ field_error('quickName') }}</p>@enderror
                        </div>
                        <div>
                            <label class="label text-xs" for="quickPhone">{{ __('appointments.patient') }}</label>
                            <input class="input-field text-sm" id="quickPhone" name="quickPhone" maxlength="32" value="{{ $old('quickPhone') }}" placeholder="{{ __('appointments.quickPhoneHint') }}">
                            @error('quickPhone')<p class="text-xs text-red-600">{{ field_error('quickPhone') }}</p>@enderror
                        </div>
                    </div>
                </details>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="doctorId">{{ __('appointments.doctor') }} <span class="text-red-500">*</span></label>
                    <select class="input-field" id="doctorId" name="doctorId">
                        <option value="">{{ __('appointments.selectDoctor') }}</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected($old('doctorId', $appointment['doctorId'] ?? '') === $doctor->id)>{{ $doctor->full_name }}</option>
                        @endforeach
                    </select>
                    @error('doctorId')<p class="text-xs text-red-600">{{ field_error('doctorId') }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="serviceId">{{ __('appointments.service') }}</label>
                    <select class="input-field" id="serviceId" name="serviceId">
                        <option value="">{{ __('appointments.noService') }}</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected($old('serviceId', $appointment['serviceId'] ?? '') === $service->id)>{{ $service->name }}</option>
                        @endforeach
                    </select>
                    @error('serviceId')<p class="text-xs text-red-600">{{ field_error('serviceId') }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="dateKey">{{ __('appointments.date') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="dateKey" name="dateKey" type="date"
                           value="{{ $old('dateKey', $defaultDateKey) }}">
                    @error('dateKey')<p class="text-xs text-red-600">{{ field_error('dateKey') }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="startTime">{{ __('appointments.startTime') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="startTime" name="startTime" type="time" value="{{ $old('startTime', '09:00') }}">
                    @error('startTime')<p class="text-xs text-red-600">{{ field_error('startTime') }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="durationMin">{{ __('appointments.duration') }} <span class="text-red-500">*</span></label>
                    <select class="input-field" id="durationMin" name="durationMin">
                        @foreach (Constants::DURATIONS as $duration)
                            <option value="{{ $duration }}" @selected((int) $old('durationMin', Constants::DEFAULT_DURATION) === $duration)>{{ $duration }} min</option>
                        @endforeach
                    </select>
                    @error('durationMin')<p class="text-xs text-red-600">{{ field_error('durationMin') }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="label" for="notes">{{ __('appointments.notes') }}</label>
                <textarea class="input-field text-sm" id="notes" name="notes" rows="2" maxlength="300">{{ $old('notes', $appointment['notes'] ?? '') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">{{ __('appointments.notesHint') }}</p>
                @error('notes')<p class="text-xs text-red-600">{{ field_error('notes') }}</p>@enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
                <a href="{{ route('appointments.index') }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
