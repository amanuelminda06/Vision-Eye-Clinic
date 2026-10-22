@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.faq.title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('site.faq.intro') }}</p>

        @if ($items === [])
            <div class="mt-8 rounded-xl border border-dashed border-brand-200 p-6 text-sm text-slate-600">{{ __('site.faq.empty') }}</div>
        @else
            <div class="mt-8 space-y-3">
                @foreach ($items as $item)
                    <details class="group card card-hover reveal p-4">
                        <summary class="flex cursor-pointer items-center justify-between gap-3 text-sm font-medium text-slate-900">
                            <span>{{ $item['question'] }}</span>
                            <span class="text-lg text-brand-700 transition-transform group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <p class="mt-3 text-sm text-slate-600">{{ $item['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        @endif

        <p class="mt-8 text-sm text-slate-600">
            <a href="/contact" class="text-brand-700 hover:underline">{{ __('site.navContact') }} →</a>
        </p>
    </div>
@endsection
