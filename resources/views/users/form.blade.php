@extends('layouts.app')

@section('title', $user === null ? __('users.newUser') : __('users.editUser'))

@php
    $old = fn (string $key, $default = '') => old($key, $default);
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">
                {{ $user === null ? __('users.newUser') : __('users.editUser') }}
            </h1>
            <a href="{{ route('users.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="card space-y-4 p-5" enctype="multipart/form-data">
            @csrf

            <div>
                <label class="label" for="name">{{ __('common.name') }} <span class="text-red-500">*</span></label>
                <input class="input-field" id="name" name="name" maxlength="100" value="{{ $old('name', $user->name ?? '') }}">
                @error('name')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label" for="email">{{ __('common.email') }} <span class="text-red-500">*</span></label>
                <input class="input-field" id="email" name="email" type="email" maxlength="254" value="{{ $old('email', $user->email ?? '') }}">
                @error('email')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label" for="role">{{ __('common.role') }} <span class="text-red-500">*</span></label>
                <select class="input-field" id="role" name="role">
                    @foreach (['OWNER', 'RECEPTION', 'STAFF'] as $value)
                        <option value="{{ $value }}" @selected($old('role', $user->role ?? 'RECEPTION') === $value)>
                            {{ __('common.role'.ucfirst(strtolower($value))) }}
                        </option>
                    @endforeach
                </select>
                @error('role')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label" for="staffId">{{ __('users.staffLink') }}</label>
                <select class="input-field" id="staffId" name="staffId">
                    <option value="">{{ __('users.noStaff') }}</option>
                    @foreach ($staffOptions as $staff)
                        <option value="{{ $staff->id }}" @selected($old('staffId', $user->staff_id ?? '') === $staff->id)>{{ $staff->full_name }}</option>
                    @endforeach
                </select>
                @error('staffId')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            @if ($user === null)
                <div>
                    <label class="label" for="password">{{ __('users.password') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="password" name="password" type="password" autocomplete="new-password">
                    <p class="mt-1 text-xs text-slate-500">{{ __('users.passwordHint') }}</p>
                    @error('password')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary">
                    {{ $user === null ? __('users.create') : __('users.update') }}
                </button>
                <a href="{{ route('users.index') }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>

        @if ($user !== null)
            <section class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('users.setPassword') }}</h2>
                <form method="POST" action="{{ route('users.set-password', $user->id) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="label text-xs" for="set-password">{{ __('users.newPassword') }}</label>
                        <input class="input-field" id="set-password" name="password" type="password" autocomplete="new-password">
                        @error('password')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="set-confirm">{{ __('users.confirmPassword') }}</label>
                        <input class="input-field" id="set-confirm" name="confirmPassword" type="password" autocomplete="new-password">
                        @error('confirmPassword')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="mustChangePassword" value="true" @checked(old('mustChangePassword') === 'true')>
                        {{ __('users.mustChangeLabel') }}
                    </label>
                    <button type="submit" class="btn-secondary">{{ __('users.setPassword') }}</button>
                </form>
            </section>
        @endif
    </div>
@endsection
