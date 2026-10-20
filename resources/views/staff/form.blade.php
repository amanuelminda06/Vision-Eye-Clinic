@extends('layouts.app')

@section('title', $staff === null ? __('nav.staff') : __('nav.staff'))

@php
    $old = fn (string $key, $default = '') => old($key, $default);
@endphp

@section('content')
    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('nav.staff') }}</h1>
            <a href="{{ route('staff.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="card space-y-4 p-5" enctype="multipart/form-data">
            @csrf

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="fullName">{{ __('common.name') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="fullName" name="fullName" maxlength="100" value="{{ $old('fullName', $staff->full_name ?? '') }}">
                    @error('fullName')<p class="text-xs text-red-600">{{ field_error('fullName') }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="jobTitle">{{ __('staff.jobTitle') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="jobTitle" name="jobTitle" maxlength="100" value="{{ $old('jobTitle', $staff->job_title ?? '') }}">
                    @error('jobTitle')<p class="text-xs text-red-600">{{ field_error('jobTitle') }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="specialty">{{ __('staff.specialty') }}</label>
                    <input class="input-field" id="specialty" name="specialty" maxlength="100" value="{{ $old('specialty', $staff->specialty ?? '') }}">
                    @error('specialty')<p class="text-xs text-red-600">{{ field_error('specialty') }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="phone">{{ __('staff.phone') }}</label>
                    <input class="input-field" id="phone" name="phone" maxlength="32" value="{{ $old('phone', $staff->phone ?? '') }}" placeholder="09… or +251…">
                    @error('phone')<p class="text-xs text-red-600">{{ field_error('phone') }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="isDoctor" value="true" @checked($old('isDoctor', ($staff->is_doctor ?? false) ? 'true' : '') === 'true')>
                    {{ __('staff.isDoctor') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="showOnWebsite" value="true" @checked($old('showOnWebsite', ($staff->show_on_website ?? false) ? 'true' : '') === 'true')>
                    {{ __('staff.showOnWebsite') }}
                </label>
            </div>

            <div>
                <label class="label" for="annualLeaveDays">{{ __('hr.balanceEntitlement') }}</label>
                <input class="input-field w-32" id="annualLeaveDays" name="annualLeaveDays" type="number" min="0"
                       value="{{ $old('annualLeaveDays', $staff->annual_leave_days ?? '') }}">
            </div>

            <div>
                <label class="label" for="photo">{{ __('staff.photo') }}</label>
                <input class="input-field" id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp">
                @error('photo')<p class="text-xs text-red-600">{{ field_error('photo') }}</p>@enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
                <a href="{{ route('staff.index') }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
