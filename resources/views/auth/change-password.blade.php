@extends('layouts.app')

@section('title', __('changePassword.title'))

@section('content')
    <div class="mx-auto max-w-sm">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('changePassword.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('changePassword.subtitle') }}</p>

        <form method="POST" action="{{ route('password.change.update') }}" class="card mt-6 space-y-4 p-6">
            @csrf

            <div>
                <label class="label" for="currentPassword">{{ __('changePassword.current') }}</label>
                <input class="input-field" id="currentPassword" name="currentPassword" type="password"
                       autocomplete="current-password" required
                       @if ($errors->has('currentPassword')) aria-invalid="true" @endif>
                @if ($errors->has('currentPassword'))
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ __('validation.'.$errors->first('currentPassword')) }}</p>
                @endif
            </div>

            <div>
                <label class="label" for="newPassword">{{ __('changePassword.next') }}</label>
                <input class="input-field" id="newPassword" name="newPassword" type="password"
                       autocomplete="new-password" required
                       @if ($errors->has('newPassword')) aria-invalid="true" @endif>
                @if ($errors->has('newPassword'))
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ __('validation.'.$errors->first('newPassword')) }}</p>
                @endif
            </div>

            <div>
                <label class="label" for="confirmPassword">{{ __('changePassword.confirm') }}</label>
                <input class="input-field" id="confirmPassword" name="confirmPassword" type="password"
                       autocomplete="new-password" required
                       @if ($errors->has('confirmPassword')) aria-invalid="true" @endif>
                @if ($errors->has('confirmPassword'))
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ __('validation.'.$errors->first('confirmPassword')) }}</p>
                @endif
            </div>

            <button type="submit" class="btn-primary w-full">{{ __('changePassword.submit') }}</button>
        </form>
    </div>
@endsection
