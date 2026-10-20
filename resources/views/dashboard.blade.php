@extends('layouts.app')

@section('title', __('dashboard.title'))

@section('content')
    {{-- Slice 1 stub: real widgets (stats, on-duty, quick links) arrive in slice 8. --}}
    <h1 class="text-xl font-semibold text-slate-900">{{ __('dashboard.title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">
        {{ __t('dashboard.subtitle', [
            'name' => $user->name,
            'role' => __('common.role'.ucfirst(strtolower($user->role))),
        ]) }}
    </p>

    <div class="card mt-6 p-6 text-sm text-slate-500">
        {{ __('dashboard.comingSoon') }}
    </div>
@endsection
