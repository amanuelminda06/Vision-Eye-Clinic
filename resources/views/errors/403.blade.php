@extends('layouts.guest')

@section('title', __('forbidden.title'))

@section('content')
    <div class="card p-6 text-center">
        <p class="text-sm font-semibold text-brand-700">403</p>
        <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ __('forbidden.title') }}</h1>
        <p class="mt-2 text-sm text-slate-500">{{ __('forbidden.message') }}</p>
        <a href="/" class="btn-secondary mt-5">{{ __('common.back') }}</a>
    </div>
@endsection
