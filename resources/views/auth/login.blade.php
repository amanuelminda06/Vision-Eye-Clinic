@extends('layouts.guest')

@section('title', __('auth.loginTitle'))

@section('content')
    <div class="card p-6">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('auth.loginTitle') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ __('auth.loginSubtitle') }}</p>

        <form method="POST" action="{{ url('/login/'.request()->route('secret')) }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label class="label" for="email">{{ __('auth.email') }}</label>
                <input class="input-field" id="email" name="email" type="email" autocomplete="email"
                       autofocus required value="{{ old('email') }}"
                       @if ($errors->has('login')) aria-invalid="true" @endif>
            </div>

            <div>
                <label class="label" for="password">{{ __('auth.password') }}</label>
                <input class="input-field" id="password" name="password" type="password"
                       autocomplete="current-password" required
                       @if ($errors->has('login')) aria-invalid="true" @endif>
            </div>

            @if ($errors->has('login'))
                <p role="alert" class="text-sm font-medium text-red-600">{{ __('auth.invalidCredentials') }}</p>
            @endif

            <button type="submit" class="btn-primary w-full">{{ __('auth.signIn') }}</button>
        </form>
    </div>
@endsection
