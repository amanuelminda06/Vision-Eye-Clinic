@extends('layouts.app')

@section('title', __('messages.newCampaign'))

@section('content')
    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.newCampaign') }}</h1>
            <a href="{{ route('messages.campaigns') }}" class="btn-secondary">{{ __('messages.backToCampaigns') }}</a>
        </div>

        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
            {{ __('messages.forbidden') }}
        </div>
    </div>
@endsection
