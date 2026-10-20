@extends('layouts.app')

@section('title', __('messages.title'))

@php
    use App\Support\ClinicTime;
    use App\Support\Messaging\Constants;
    use App\Support\Messaging\Provider;

    $statusLabel = fn (string $status): string => __('messages.'.(Constants::MESSAGE_STATUS_LABEL_KEYS[$status] ?? $status));
    $sourceLabel = fn (string $source): string => __('messages.'.(Constants::MESSAGE_SOURCE_LABEL_KEYS[$source] ?? $source));
    $templateLabel = fn (string $key): string => __('messages.'.(Constants::TEMPLATE_LABEL_KEYS[$key] ?? $key));

    $statusClass = fn (string $status): string => match ($status) {
        'PENDING' => 'bg-slate-100 text-slate-600',
        'OPENED' => 'bg-amber-50 text-amber-700',
        'SENT' => 'bg-emerald-50 text-emerald-700',
        'SKIPPED' => 'bg-slate-100 text-slate-500',
        'BLOCKED' => 'bg-red-50 text-red-700',
        default => 'bg-slate-100 text-slate-500',
    };

    $sections = [
        ['rows' => $groups['overdue'], 'title' => __('messages.groupOverdue'), 'tone' => 'border-red-200 bg-red-50/40'],
        ['rows' => $groups['dueNow'], 'title' => __('messages.groupDueNow'), 'tone' => 'border-amber-200 bg-amber-50/40'],
        ['rows' => $groups['laterToday'], 'title' => __('messages.groupLaterToday'), 'tone' => ''],
        ['rows' => $groups['doneToday'], 'title' => __('messages.groupDoneToday'), 'tone' => ''],
        ['rows' => $groups['blocked'], 'title' => __('messages.groupBlocked'), 'tone' => 'border-red-200 bg-red-50/40'],
    ];
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.title') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('messages.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('partials.export-links', ['base' => route('messages.export'), 'query' => ''])
                <a href="{{ route('messages.library') }}" class="btn-secondary">{{ __('messages.libraryLink') }}</a>
                <a href="{{ route('messages.drafts') }}" class="btn-secondary">{{ __('messages.draftsLink') }}</a>
                @if ($canEditTemplates)
                    <a href="{{ route('messages.templates') }}" class="btn-secondary">{{ __('messages.templatesLink') }}</a>
                @endif
                @if ($canSendCampaigns)
                    <a href="{{ route('messages.campaigns') }}" class="btn-secondary">{{ __('messages.campaignsLink') }}</a>
                @endif
                @if ($canEditTemplates)
                    <a href="{{ route('messages.reports') }}" class="btn-secondary">{{ __('messages.reportsLink') }}</a>
                @endif
            </div>
        </div>

        {{-- Counters --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-xs uppercase text-slate-500">{{ __('messages.groupDueNow') }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">{{ $counts['dueToday'] }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase text-slate-500">{{ __('messages.groupOverdue') }}</p>
                <p class="mt-1 text-xl font-semibold {{ $counts['overdue'] > 0 ? 'text-red-600' : 'text-slate-900' }} tabular-nums">{{ $counts['overdue'] }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase text-slate-500">{{ __('messages.groupBlocked') }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">{{ count($groups['blocked']) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs uppercase text-slate-500">{{ __('messages.groupDoneToday') }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums">{{ count($groups['doneToday']) }}</p>
            </div>
        </div>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('messages.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        @foreach ($sections as $section)
            @if ($section['rows'] !== [])
                <section class="card p-4 {{ $section['tone'] }}">
                    <h2 class="text-sm font-semibold text-slate-800">
                        {{ $section['title'] }} <span class="text-slate-500">({{ count($section['rows']) }})</span>
                    </h2>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">{{ __('messages.due') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('messages.patient') ?? __('common.name') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('messages.template') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('messages.customMessage') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('common.status') }}</th>
                                    <th class="py-2 text-right font-medium">{{ __('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['rows'] as $row)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="py-2 pr-3 text-xs text-slate-600">{{ ClinicTime::clinicTimeKey($row['dueAt'], $timezone) }}</td>
                                        <td class="py-2 pr-3">
                                            <p class="font-medium text-slate-900">{{ $row['patientName'] }}</p>
                                            <p class="text-xs text-slate-500">{{ $row['patientPhoneE164'] }}</p>
                                        </td>
                                        <td class="py-2 pr-3 text-xs">
                                            <p>{{ $templateLabel($row['templateKey']) }}</p>
                                            <p class="text-slate-400">{{ $sourceLabel($row['sourceType']) }}</p>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <p class="whitespace-pre-line text-xs text-slate-700">{{ $row['renderedMessage'] }}</p>
                                            @if ($row['blockedReason'])
                                                <p class="mt-1 text-xs font-medium text-red-600">
                                                    {{ __('messages.'.(Constants::BLOCKED_REASON_LABEL_KEYS[$row['blockedReason']] ?? $row['blockedReason'])) }}
                                                </p>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                                        </td>
                                        <td class="py-2">
                                            <div class="flex flex-wrap items-center justify-end gap-2">
                                                @if ($row['patientPhoneE164'] && $row['status'] !== 'BLOCKED' && $row['status'] !== 'SENT' && $row['status'] !== 'SKIPPED')
                                                    <a href="{{ Provider::buildSmsLink($row['patientPhoneE164'], $row['renderedMessage'], $platform) }}"
                                                       class="btn-primary px-2 py-1 text-xs">{{ __('messages.openLink') }}</a>
                                                @endif

                                                @if (! in_array($row['status'], ['SENT', 'SKIPPED', 'CANCELLED'], true))
                                                    <form method="POST" action="{{ route('messages.open', $row['id']) }}">
                                                        @csrf
                                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.open') }}</button>
                                                    </form>

                                                    <form method="POST" action="{{ route('messages.sent', $row['id']) }}">
                                                        @csrf
                                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.yesSent') }}</button>
                                                    </form>

                                                    <form method="POST" action="{{ route('messages.postpone', $row['id']) }}">
                                                        @csrf
                                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.notYet') }}</button>
                                                    </form>

                                                    <form method="POST" action="{{ route('messages.skip', $row['id']) }}" class="flex items-center gap-1">
                                                        @csrf
                                                        <input type="hidden" name="reason" value="{{ $row['skipReason'] ?? '' }}" placeholder="{{ __('messages.skipReason') }}" class="input-field w-32 px-2 py-1 text-xs">
                                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.skip') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endforeach

        @if ($groups['dueNow'] === [] && $groups['overdue'] === [] && $groups['laterToday'] === []
            && $groups['doneToday'] === [] && $groups['blocked'] === [])
            <p class="text-sm text-slate-500">{{ __('messages.noMessages') }}</p>
        @endif
    </div>
@endsection
