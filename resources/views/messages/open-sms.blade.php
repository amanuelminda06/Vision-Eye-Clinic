@extends('layouts.app')

@section('title', __('messages.openLink'))

@section('content')
    <div class="mx-auto max-w-md">
        <div class="card space-y-4 p-6 text-center">
            <h1 class="text-lg font-semibold text-slate-900">{{ __('messages.openLink') }}</h1>
            <p class="text-sm text-slate-600">{{ __('messages.openSmsHint') }}</p>

            <div class="flex flex-wrap items-center justify-center gap-3">
                <a id="openSmsLink" href="{{ $link }}" class="btn-primary">{{ __('messages.openLink') }}</a>
                <a href="{{ route('messages.index') }}" class="btn-secondary">{{ __('messages.backToMessages') }}</a>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var link = @json($link);
        var anchor = document.getElementById('openSmsLink');

        // Hand straight over to the handset's SMS app. Browsers that block a
        // scripted custom-scheme navigation still have the button above.
        if (link && anchor) {
            window.location.href = link;
        }
    })();
</script>
@endpush
