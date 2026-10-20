@extends('layouts.app')

@section('title', __('messages.title'))

@php
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
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.title') }}</h1>
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

        <p class="text-sm text-slate-500">{{ __('messages.subtitle') }}</p>

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

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('messages.template') }}</th>
                        <th class="px-3 py-2">{{ __('messages.customMessage') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2">{{ __('messages.due') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $row['patientName'] }}</p>
                                <p class="text-xs text-slate-500">{{ $row['patientPhoneE164'] }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <p>{{ $templateLabel($row['templateKey']) }}</p>
                                <p class="text-slate-400">{{ $sourceLabel($row['sourceType']) }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <p class="whitespace-pre-line text-xs text-slate-700">{{ $row['renderedMessage'] }}</p>
                                @if ($row['blockedReason'])
                                    <p class="mt-1 text-xs font-medium text-red-600">
                                        {{ __('messages.'.(Constants::BLOCKED_REASON_LABEL_KEYS[$row['blockedReason']] ?? $row['blockedReason'])) }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['dueAt']?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($row['patientPhoneE164'] && $row['status'] !== 'BLOCKED')
                                        <a href="{{ Provider::buildSmsLink($row['patientPhoneE164'], $row['renderedMessage'], $platform) }}"
                                           class="btn-primary px-2 py-1 text-xs">{{ __('messages.openLink') }}</a>
                                    @endif

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
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">{{ __('messages.noMessages') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
