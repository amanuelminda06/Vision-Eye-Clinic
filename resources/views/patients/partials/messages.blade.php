@php
    use App\Support\Messaging\Constants;

    $statusLabel = fn (string $status): string => __('messages.'.(Constants::MESSAGE_STATUS_LABEL_KEYS[$status] ?? $status));
    $sourceLabel = fn (string $source): string => __('messages.'.(Constants::MESSAGE_SOURCE_LABEL_KEYS[$source] ?? $source));

    $statusClass = fn (string $status): string => match ($status) {
        'SENT' => 'bg-emerald-50 text-emerald-700',
        'BLOCKED' => 'bg-red-50 text-red-700',
        'SKIPPED' => 'bg-slate-100 text-slate-500',
        'EXPIRED' => 'bg-amber-50 text-amber-700',
        'CANCELLED' => 'bg-slate-100 text-slate-500',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp

@if (count($messages) === 0)
    <div class="card p-5 text-sm text-slate-600">{{ __('messages.noMessages') }}</div>
@else
    <div class="card overflow-x-auto p-5">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                    <th class="py-2 pr-3 font-medium">{{ __('messages.due') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('messages.template') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('messages.customMessage') }}</th>
                    <th class="py-2 font-medium">{{ __('common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($messages as $message)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="py-2 pr-3 text-slate-800">{{ $message['dueAt']?->format('Y-m-d H:i') }}</td>
                        <td class="py-2 pr-3">
                            <span class="text-slate-700">{{ $sourceLabel($message['sourceType']) }}</span>
                        </td>
                        <td class="py-2 pr-3 text-slate-600">{{ $message['renderedMessage'] }}</td>
                        <td class="py-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($message['status']) }}">
                                {{ $statusLabel($message['status']) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
