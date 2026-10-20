@extends('layouts.app')

@section('title', __('messages.reportsLink'))

@section('content')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.reportsLink') }}</h1>
            <a href="{{ route('messages.index') }}" class="btn-secondary">{{ __('messages.backToOutbox') }}</a>
        </div>

        @forelse ($groups as $group)
            <section class="card space-y-2 p-4">
                <h2 class="text-sm font-semibold text-slate-800">
                    {{ $group['dateKey'] }} · {{ $group['count'] }}
                </h2>
                <ul class="space-y-1 text-sm">
                    @foreach ($group['tasks'] as $task)
                        <li class="flex items-center justify-between border-b border-brand-50 py-1 last:border-0">
                            <span class="text-slate-700">{{ $task['patientName'] }}</span>
                            <span class="text-xs text-slate-500">{{ $task['templateKey'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-sm text-slate-500">{{ __('common.noResults') }}</p>
        @endforelse
    </div>
@endsection
