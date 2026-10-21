@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.services.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-600">{{ __('site.services.intro') }}</p>

        @if ($grouped === [])
            <div class="mt-8 rounded-xl border border-dashed border-brand-200 p-6 text-sm text-slate-600">{{ __('site.services.empty') }}</div>
        @else
            @foreach ($grouped as $category => $services)
                <section class="mt-10">
                    <h2 class="text-xl font-semibold text-slate-900">
                        {{ __('site.services.category') }}:
                        {{ \App\Support\Services\Categories::isServiceCategory($category)
                            ? __('services.'.\App\Support\Services\Categories::labelKey($category))
                            : $category }}
                    </h2>

                    <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($services as $service)
                            <article class="card overflow-hidden p-0">
                                @php $image = \App\Support\PublicSite\Format::publicServiceImageUrl($service['id'], $service['imageUrl']); @endphp
                                @if ($image)
                                    <img src="{{ $image }}" alt="" class="h-40 w-full object-cover"
                                         data-fallback="placeholder"
                                         data-fallback-text="{{ mb_strtoupper(mb_substr(app()->getLocale() === 'am' ? ($service['nameAm'] ?: $service['nameEn']) : ($service['nameEn'] ?: $service['name']), 0, 1)) }}">
                                @endif
                                <div class="space-y-2 p-4">
                                    <h3 class="text-base font-semibold text-slate-900">
                                        {{ app()->getLocale() === 'am' ? ($service['nameAm'] ?: ($service['nameEn'] ?: $service['name'])) : ($service['nameEn'] ?: $service['name']) }}
                                    </h3>
                                    @php $description = app()->getLocale() === 'am' ? ($service['descriptionAm'] ?: $service['descriptionEn']) : $service['descriptionEn']; @endphp
                                    @if ($description)
                                        <p class="text-sm text-slate-600">{{ $description }}</p>
                                    @endif
                                    <a href="/book" class="text-sm text-brand-700 hover:underline">{{ __('site.services.bookService') }}</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
@endsection
