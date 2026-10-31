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
                @if ($canSendCampaigns)
                    <a href="{{ route('messages.campaigns.new') }}" class="btn-primary">{{ __('messages.newMessageLink') }}</a>
                @endif
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

        @php
            $allRows = array_merge(
                $groups['overdue'] ?? [],
                $groups['dueNow'] ?? [],
                $groups['laterToday'] ?? [],
                $groups['blocked'] ?? [],
                $groups['doneToday'] ?? [],
            );
            usort($allRows, fn ($a, $b) => ($a['dueAt']?->getTimestamp() ?? 0) <=> ($b['dueAt']?->getTimestamp() ?? 0));
        @endphp

        @if ($allRows === [])
            <p class="text-sm text-slate-500">{{ __('messages.noMessages') }}</p>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">{{ __('messages.due') }}</th>
                            <th class="px-3 py-2">{{ __('messages.patient') ?? __('common.name') }}</th>
                            <th class="px-3 py-2">{{ __('messages.customMessage') }}</th>
                            <th class="px-3 py-2">{{ __('common.status') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($allRows as $row)
                            <tr class="border-b border-brand-50 last:border-0">
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    {{ $row['dueAt']?->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-3 py-2 font-medium text-slate-900">
                                    {{ $row['patientName'] }}
                                </td>
                                <td class="px-3 py-2 text-slate-600">
                                    {{ \Illuminate\Support\Str::limit($row['renderedMessage'], 80) }}
                                </td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">
                                        {{ $statusLabel($row['status']) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @if ($row['patientPhoneE164'] && ! in_array($row['status'], ['BLOCKED', 'SENT', 'SKIPPED', 'CANCELLED'], true))
                                            <a href="{{ Provider::buildSmsLink($row['patientPhoneE164'], $row['renderedMessage'], $platform) }}"
                                               class="btn-primary px-2 py-1 text-xs send-link"
                                               data-task-id="{{ $row['id'] }}">{{ __('messages.openLink') }}</a>
                                        @endif

                                        @if (! in_array($row['status'], ['SENT', 'SKIPPED', 'CANCELLED'], true))
                                            <form method="POST" action="{{ route('messages.postpone', $row['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.notYet') }}</button>
                                            </form>

                                            <form method="POST" action="{{ route('messages.skip', $row['id']) }}" class="flex items-center gap-1">
                                                @csrf
                                                <input type="hidden" name="reason" value="{{ $row['skipReason'] ?? '' }}">
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
        @endif

        <script>
            (function () {
                document.querySelectorAll('.send-link').forEach(function (link) {
                    link.addEventListener('click', function (e) {
                        e.preventDefault();
                        var taskId = link.getAttribute('data-task-id');
                        var href = link.getAttribute('href');
                        if (taskId) {
                            var url = '{{ url('/messages') }}/' + taskId + '/sent';
                            var token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                            try {
                                fetch(url, {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': token || '',
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: '_token=' + encodeURIComponent(token || ''),
                                    keepalive: true,
                                });
                            } catch (err) { /* ignore */ }
                        }
                        if (href) {
                            window.location.href = href;
                        }
                    });
                });
            })();
        </script>
@endsection
