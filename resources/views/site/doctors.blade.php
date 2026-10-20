@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.doctors.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-600">{{ $intro }}</p>

        @if ($doctors === [])
            <div class="mt-8 rounded-xl border border-dashed border-brand-200 p-6 text-sm text-slate-600">{{ __('site.doctors.empty') }}</div>
        @else
            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($doctors as $doctor)
                    @include('site.partials.doctor-card', ['doctor' => $doctor])
                @endforeach
            </div>
        @endif
    </div>
@endsection
