@extends('layouts.app')

@section('title', __('nav.audit'))

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('nav.audit') }}</h1>
            @include('partials.export-links', [
                'base' => route('audit.export'),
                'query' => '?'.http_build_query(array_filter($filters, static fn ($v): bool => $v !== null && $v !== '')),
            ])
        </div>

        <form method="GET" action="{{ route('audit.index') }}" class="card flex flex-wrap items-end gap-3 p-3">
            <div>
                <label class="label text-xs" for="userId">{{ __('common.name') }}</label>
                <input class="input-field text-sm" id="userId" name="userId" maxlength="64" value="{{ $filters['userId'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="entity">{{ __('common.status') }}</label>
                <input class="input-field text-sm" id="entity" name="entity" maxlength="64" value="{{ $filters['entity'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="action">{{ __('messages.template') }}</label>
                <input class="input-field text-sm" id="action" name="action" maxlength="64" value="{{ $filters['action'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="from">{{ __('sales.from') }}</label>
                <input class="input-field text-sm" id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="to">{{ __('sales.to') }}</label>
                <input class="input-field text-sm" id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
            </div>
            <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
        </form>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('messages.due') }}</th>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('messages.template') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2">{{ __('appointments.notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['createdAt']?->toDateTimeString() }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['userName'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                <span class="font-medium text-slate-800">{{ $row['action'] }}</span>
                                <span class="block text-slate-400">{{ $row['entity'] }} · {{ $row['entityId'] }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['ip'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if ($row['oldJson'] !== null || $row['newJson'] !== null)
                                    <details>
                                        <summary class="cursor-pointer text-brand-700 hover:underline">{{ __('common.edit') }}</summary>
                                        @if ($row['oldJson'] !== null)
                                            <pre class="mt-1 overflow-x-auto rounded bg-slate-50 p-2 text-[10px]">{{ json_encode($row['oldJson'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                        @if ($row['newJson'] !== null)
                                            <pre class="mt-1 overflow-x-auto rounded bg-emerald-50 p-2 text-[10px]">{{ json_encode($row['newJson'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                    </details>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-sm text-slate-500">
            {{ __('common.showing', ['from' => $rows === [] ? 0 : (($page - 1) * \App\Http\Controllers\AuditController::PAGE_SIZE) + 1, 'to' => (($page - 1) * \App\Http\Controllers\AuditController::PAGE_SIZE) + count($rows), 'total' => $total]) }}
        </p>

        <nav class="flex items-center justify-center gap-3 text-sm">
            @if ($page > 1)
                <a href="{{ route('audit.index', array_merge(request()->query(), ['page' => $page - 1])) }}" class="btn-secondary px-3 py-1">{{ __('common.previous') }}</a>
            @endif
            <span class="text-slate-500">{{ __('common.page') }} {{ $page }} {{ __('common.of') }} {{ $pageCount }}</span>
            @if ($page < $pageCount)
                <a href="{{ route('audit.index', array_merge(request()->query(), ['page' => $page + 1])) }}" class="btn-secondary px-3 py-1">{{ __('common.next') }}</a>
            @endif
        </nav>
    </div>
@endsection
