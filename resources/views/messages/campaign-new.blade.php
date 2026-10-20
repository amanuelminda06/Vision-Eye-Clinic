@extends('layouts.app')

@section('title', __('messages.campaignsForbidden') === 'messages.campaignsForbidden' ? __('messages.newCampaign') : __('messages.newCampaign'))

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.newCampaign') }}</h1>
            <a href="{{ route('messages.campaigns') }}" class="btn-secondary">{{ __('messages.backToCampaigns') }}</a>
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

        {{-- Audience preview --}}
        <section class="card space-y-3 p-4">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.preview') }}</h2>
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.showing', ['from' => 1, 'to' => 1, 'total' => $preview['matched']]) }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['matched'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.active') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['eligible'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.inactive') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['excluded']['inactive'] + $preview['excluded']['doNotMessage'] + $preview['excluded']['invalidPhone'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('messages.campaignsTitle') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['cap'] }}</p>
                </div>
            </div>

            @if ($preview['overCap'])
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('messages.campaignOverCap') }}</p>
            @endif

            @if ($preview['groupBlockReasons'] !== [])
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ __('messages.groupNotAllowed') }}</p>
            @endif
        </section>

        {{-- The campaign form --}}
        <form method="POST" action="{{ route('messages.campaigns.store') }}" class="card space-y-4 p-4">
            @csrf
            <input type="hidden" name="filter" value="{{ json_encode($filter) }}">

            <div>
                <label class="label" for="title">{{ __('common.name') }}</label>
                <input class="input-field" id="title" name="title" maxlength="120" value="{{ old('title') }}">
                @error('title')<p class="text-xs text-red-600">{{ field_error('title') }}</p>@enderror
            </div>

            <div>
                <label class="label" for="body">{{ __('messages.customMessage') }}</label>
                <textarea class="input-field" id="body" name="body" rows="4" maxlength="1000">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-red-600">{{ field_error('body') }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label text-xs" for="languageMode">{{ __('messages.languageMode') }}</label>
                    <select class="input-field text-sm" id="languageMode" name="languageMode">
                        <option value="PATIENT" @selected(old('languageMode', 'PATIENT') === 'PATIENT')>{{ __('messages.languagePatient') }}</option>
                        <option value="FIXED" @selected(old('languageMode') === 'FIXED')>{{ __('messages.languageFixed') }}</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="fixedLanguage">{{ __('messages.messageLanguage') }}</label>
                    <select class="input-field text-sm" id="fixedLanguage" name="fixedLanguage">
                        <option value="en" @selected(old('fixedLanguage', 'en') === 'en')>English</option>
                        <option value="am" @selected(old('fixedLanguage') === 'am')>አማርኛ</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="deliveryMode">{{ __('messages.deliveryIndividualShort') }}</label>
                    <select class="input-field text-sm" id="deliveryMode" name="deliveryMode">
                        <option value="INDIVIDUAL" @selected(old('deliveryMode', 'INDIVIDUAL') === 'INDIVIDUAL')>{{ __('messages.deliveryIndividualShort') }}</option>
                        <option value="GROUP" @selected(old('deliveryMode') === 'GROUP')>{{ __('messages.deliveryGroupShort') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="label text-xs" for="groupBatchSize">{{ __('messages.batchSize', ['size' => 15]) }}</label>
                <input class="input-field w-32" id="groupBatchSize" name="groupBatchSize" type="number" min="1" max="100" value="{{ old('groupBatchSize', 15) }}">
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledgedGroupWarning" value="true" @checked(old('acknowledgedGroupWarning') === 'true')>
                <span>{{ __('messages.groupAckText') }}</span>
            </label>

            <button type="submit" class="btn-primary">{{ __('messages.newCampaign') }}</button>
        </form>
    </div>
@endsection
