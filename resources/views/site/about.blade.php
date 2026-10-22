@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.meta.aboutTitle') }}</h1>
        <p class="mt-4 text-base text-slate-600">
            {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['home_intro']) }}
        </p>

        <div class="mt-8 grid gap-5 sm:grid-cols-3">
            @for ($i = 1; $i <= 3; $i++)
                <div class="card card-hover reveal p-5">
                    <h2 class="text-sm font-semibold text-slate-900">{{ __('site.home.why'.$i.'Title') }}</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ __('site.home.why'.$i.'Text') }}</p>
                </div>
            @endfor
        </div>

        <p class="mt-8 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['footer_note']) }}
        </p>
    </div>
@endsection
