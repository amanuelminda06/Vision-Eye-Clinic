@extends('layouts.guest')

@section('title', __('app.name'))

@section('content')
    {{-- Slice 7 replaces this with the real public marketing site. --}}
    <div class="card p-6 text-center">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('app.name') }}</h1>
    </div>
@endsection
