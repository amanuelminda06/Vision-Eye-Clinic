@extends('layouts.app')

@section('title', __('requests.title'))

@php
    use App\Support\Phone;

    $statusLabel = fn (string $status): string => __('requests.'.match ($status) {
        'NEW' => 'statusNew',
        'CONTACTED' => 'statusContacted',
        'CONVERTED' => 'statusConverted',
        'DECLINED' => 'statusDeclined',
        default => 'statusSpam',
    });

    $statusClass = fn (string $status): string => match ($status) {
        'NEW' => 'bg-red-50 text-red-700',
        'CONTACTED' => 'bg-amber-50 text-amber-700',
        'CONVERTED' => 'bg-emerald-50 text-emerald-700',
        default => 'bg-slate-100 text-slate-500',
    };

    $windowLabel = fn (?string $window): string => __('requests.'.match ($window) {
        'morning' => 'windowMorning',
        'afternoon' => 'windowAfternoon',
        'evening' => 'windowEvening',
        default => 'windowAny',
    });
@endphp

@section('content')
    <div class="space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('requests.title') }}</h1>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('requests.'.session('actionError')) }}
            </div>
        @endif

        @if (session('actionOk'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">
                {{ __('requests.'.session('actionOk')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('requests.index') }}" class="{{ $status === null ? 'btn-primary' : 'btn-secondary' }} text-sm">
                {{ __('requests.filterAll') }}
                @if ($newCount !== null && $newCount > 0)
                    <span class="ml-1 rounded-full bg-red-100 px-1.5 text-xs text-red-700">{{ $newCount }}</span>
                @endif
            </a>

            @foreach (['NEW' => 'statusNew', 'CONTACTED' => 'statusContacted', 'CONVERTED' => 'statusConverted', 'DECLINED' => 'statusDeclined', 'SPAM' => 'statusSpam'] as $key => $labelKey)
                <a href="{{ route('requests.index', ['status' => $key]) }}"
                   class="{{ $status === $key ? 'btn-primary' : 'btn-secondary' }} text-sm">
                    {{ __('requests.'.$labelKey) }}
                </a>
            @endforeach
        </div>

        @if ($rows === [])
            <p class="text-sm text-slate-500">{{ __('requests.empty') }}</p>
        @endif

        <div class="space-y-3">
            @foreach ($rows as $row)
                <article class="card p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-slate-900">{{ $row['name'] }}</p>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                                @if ($row['language'] === 'am')
                                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs text-brand-700">አማርኛ</span>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-slate-600">
                                <a href="tel:{{ $row['phoneE164'] }}" class="hover:underline">{{ Phone::display($row['phoneE164']) ?? $row['phoneE164'] }}</a>
                            </p>

                            <p class="mt-1 text-xs text-slate-500">
                                {{ __('requests.preferredDate') }}: <span class="font-medium text-slate-700">{{ $row['preferredDate'] }}</span>
                                · {{ $windowLabel($row['preferredTimeWindow']) }}
                            </p>

                            @if ($row['serviceInterest'])
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ __('requests.serviceInterest') }}: <span class="text-slate-700">{{ $row['serviceInterest'] }}</span>
                                </p>
                            @endif

                            @if ($row['message'])
                                <p class="mt-2 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $row['message'] }}</p>
                            @endif

                            <p class="mt-2 text-xs text-slate-400">
                                {{ __('requests.submittedAt', ['date' => $row['createdAtLabel']]) }}
                                @if ($row['waitingMinutes'] > 0)
                                    · {{ __('requests.ageMinutes', ['minutes' => $row['waitingMinutes']]) }}
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($row['status'] === 'CONVERTED' && $row['appointmentId'])
                                <a href="{{ route('appointments.edit', $row['appointmentId']) }}" class="btn-secondary text-xs">{{ __('requests.viewAppointment') }}</a>
                            @else
                                <form method="POST" action="{{ route('requests.status', $row['id']) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="CONTACTED">
                                    <button type="submit" class="btn-secondary text-xs">{{ __('requests.markContacted') }}</button>
                                </form>

                                <form method="POST" action="{{ route('requests.status', $row['id']) }}"
                                      onsubmit="return confirm('{{ __('requests.confirmDecline') }}')">
                                    @csrf
                                    <input type="hidden" name="status" value="DECLINED">
                                    <button type="submit" class="btn-secondary text-xs">{{ __('requests.decline') }}</button>
                                </form>

                                <form method="POST" action="{{ route('requests.status', $row['id']) }}"
                                      onsubmit="return confirm('{{ __('requests.confirmSpam') }}')">
                                    @csrf
                                    <input type="hidden" name="status" value="SPAM">
                                    <button type="submit" class="btn-secondary text-xs">{{ __('requests.spam') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if ($row['status'] !== 'CONVERTED')
                        <details class="mt-3 border-t border-slate-100 pt-3">
                            <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('requests.convert') }}</summary>

                            <p class="mt-2 text-xs text-slate-500">{{ __('requests.convertHint') }}</p>

                            <form method="POST" action="{{ route('requests.convert', $row['id']) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf

                                <div>
                                    <label class="label" for="doctor-{{ $row['id'] }}">{{ __('requests.assignDoctor') }}</label>
                                    <select class="input-field text-sm" id="doctor-{{ $row['id'] }}" name="doctorId" required>
                                        <option value="">{{ __('requests.selectDoctor') }}</option>
                                        @foreach ($doctors as $doctor)
                                            <option value="{{ $doctor->id }}">{{ $doctor->full_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('doctorId')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="label" for="service-{{ $row['id'] }}">{{ __('requests.service') }}</label>
                                    <select class="input-field text-sm" id="service-{{ $row['id'] }}" name="serviceId">
                                        <option value="">{{ __('requests.noService') }}</option>
                                        @foreach ($services as $service)
                                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="label" for="notes-{{ $row['id'] }}">{{ __('requests.notes') }}</label>
                                    <textarea class="input-field text-sm" id="notes-{{ $row['id'] }}" name="notes" rows="2"
                                              maxlength="500" placeholder="{{ __('requests.notesHint') }}">{{ $row['message'] }}</textarea>
                                </div>

                                <div class="sm:col-span-2">
                                    <button type="submit" class="btn-primary">{{ __('requests.convertTitle') }}</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
@endsection
