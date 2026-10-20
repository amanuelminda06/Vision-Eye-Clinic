@extends('layouts.app')

@section('title', __('messages.campaignsTitle'))

@php
    use App\Support\Messaging\Constants;
    use App\Support\Messaging\Provider;

    $statusLabel = fn (string $status): string => __('messages.'.(Constants::CAMPAIGN_STATUS_LABEL_KEYS[$status] ?? $status));
    $batchStatusLabel = fn (string $status): string => __('messages.'.(Constants::BATCH_STATUS_LABEL_KEYS[$status] ?? $status));
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ $campaign['title'] }}</h1>
            <a href="{{ route('messages.campaigns') }}" class="btn-secondary">{{ __('messages.backToCampaigns') }}</a>
        </div>

        <p class="text-sm text-slate-500">
            {{ $statusLabel($campaign['status']) }} ·
            {{ $campaign['deliveryMode'] === 'GROUP' ? __('messages.deliveryGroupShort') : __('messages.deliveryIndividualShort') }} ·
            {{ $campaign['taskCount'] }} {{ __('messages.sampleOf', ['shown' => $campaign['taskCount'], 'total' => $campaign['taskCount']]) }}
        </p>

        @if ($campaign['deliveryMode'] === 'GROUP')
            <section class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.ongoingGroups') }}</h2>
                <p class="text-xs text-slate-500">{{ __('messages.groupBatchNote') }}</p>

                @forelse ($batches as $batch)
                    <div class="rounded-lg border border-brand-100 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-slate-800">
                                    {{ __('messages.batchGroup', ['n' => $batch['batchNumber']]) }}
                                    · {{ __('messages.batchSize', ['size' => $batch['size']]) }}
                                </p>
                                <p class="text-xs text-slate-500">{{ $batchStatusLabel($batch['status']) }}</p>
                            </div>

                            @if ($batch['status'] === 'PENDING')
                                <form method="POST" action="{{ route('messages.campaigns.batch', [$campaign['id'], $batch['id']]) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="action" value="sent">
                                    <button type="submit" class="btn-primary px-2 py-1 text-xs">{{ __('messages.batchMarkSent') }}</button>
                                </form>

                                <form method="POST" action="{{ route('messages.campaigns.batch', [$campaign['id'], $batch['id']]) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="action" value="skip">
                                    <input type="text" name="reason" placeholder="{{ __('messages.batchSkipReason') }}" class="input-field w-40 px-2 py-1 text-xs">
                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('messages.batchSkip') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('messages.noBatches') }}</p>
                @endforelse
            </section>
        @endif

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('messages.customMessage') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $task['patientName'] }}</p>
                                <p class="text-xs text-slate-500">{{ $task['patientPhoneE164'] }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $task['renderedMessage'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ __('messages.'.(Constants::MESSAGE_STATUS_LABEL_KEYS[$task['status']] ?? $task['status'])) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
