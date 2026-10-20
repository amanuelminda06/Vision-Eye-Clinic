@php
    /** @var string $base  e.g. route('patients.export') */
    /** @var string $query  e.g. '?q=…&status=true' (already built, no 'page') */
    $joiner = $query === '' ? '?' : $query.'&';
@endphp
<div class="flex items-center gap-2">
    <a href="{{ $base.$joiner.'format=csv' }}" download class="btn-secondary text-xs">{{ __('export.formatCsv') }}</a>
    <a href="{{ $base.$joiner.'format=xlsx' }}" download class="btn-secondary text-xs">{{ __('export.formatXlsx') }}</a>
</div>
