@extends('layouts.app')

@section('title', __('messages.campaignsTitle'))

@php
    use App\Support\Messaging\Constants;

    $statusLabel = fn (string $status): string => __('messages.'.(Constants::CAMPAIGN_STATUS_LABEL_KEYS[$status] ?? $status));
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.campaignsTitle') }}</h1>
            @if ($canSend)
                <a href="{{ route('messages.campaigns.new') }}" class="btn-primary">{{ __('messages.newCampaign') }}</a>
            @endif
        </div>

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
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2">{{ __('messages.deliveryIndividualShort') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['title'] }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ $statusLabel($row['status']) }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">
                                {{ $row['deliveryMode'] === 'GROUP' ? __('messages.deliveryGroupShort') : __('messages.deliveryIndividualShort') }}
                                · {{ $row['taskCount'] }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('messages.campaigns.show', $row['id']) }}" class="text-xs text-brand-700 hover:underline">{{ __('common.edit') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">{{ __('messages.noCampaigns') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
