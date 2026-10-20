@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.privacy.title') }}</h1>

        <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('site.privacy.updated') }}</p>

        <div class="mt-6 space-y-4 text-sm leading-relaxed text-slate-700">
            @foreach ($paragraphs as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>
    </div>
@endsection
