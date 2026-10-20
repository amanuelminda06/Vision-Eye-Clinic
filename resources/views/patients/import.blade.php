@extends('layouts.app')

@section('title', __('patients.importTitle'))

@php
    use App\Support\Patients\PatientImport;

    $ti = fn (string $key, array $replace = []) => $replace === [] ? __("patients.import.$key") : __t("patients.import.$key", $replace);
@endphp

@section('content')
    <div class="space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('patients.importTitle') }}</h1>

        @if ($done)
            <div class="card space-y-4 p-6">
                <p class="text-sm font-medium text-emerald-700">
                    {{ $ti('done', ['imported' => $done['imported'], 'skipped' => $done['skipped']]) }}
                </p>
                <div class="flex items-center gap-3">
                    <a href="{{ route('patients.index') }}" class="btn-primary">{{ __('patients.title') }}</a>
                    <a href="{{ route('patients.import') }}" class="btn-secondary">{{ $ti('importAnother') }}</a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('patients.import.store') }}" enctype="multipart/form-data" class="card max-w-4xl space-y-4 p-6">
                @csrf

                <div>
                    <label class="label" for="file">{{ $ti('chooseFile') }}</label>
                    <input class="input-field" id="file" name="file" type="file" accept=".csv,text/csv" @if (! $preview) required @endif>
                    <p class="mt-1 text-xs text-slate-500">{{ $ti('csvHint') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $ti('columnsHint') }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ $ti('exampleHint') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" name="step" value="preview" class="btn-primary">{{ $ti('validate') }}</button>
                    <a href="{{ route('patients.template') }}" class="btn-secondary">{{ $ti('downloadTemplate') }}</a>
                </div>

                @if ($localError)
                    <p role="alert" class="text-sm font-medium text-red-600">{{ $ti('errors.'.$localError) }}</p>
                @else
                    @if ($errors->any())
                        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($preview)
                    <input type="hidden" name="csv" value="{{ $preview['csv'] }}">
                    <input type="hidden" name="confirm" value="true">
                    <div class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <p class="font-medium text-slate-800">{{ $ti('previewTitle') }}</p>
                            <p class="text-slate-600">{{ $ti('summary', ['valid' => $preview['validCount'], 'errors' => $preview['errorCount']]) }}</p>
                        </div>

                        <div class="mt-3 max-h-80 overflow-auto">
                            <table class="w-full text-left text-xs" id="import-preview-table">
                                <thead class="sticky top-0 border-b border-slate-200 bg-white uppercase text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2 font-medium">{{ $ti('rowCol') }}</th>
                                        <th class="px-2 py-2 font-medium">{{ __('patients.fullName') }}</th>
                                        <th class="px-2 py-2 font-medium">{{ __('patients.phone') }}</th>
                                        <th class="px-2 py-2 font-medium">{{ __('common.status') }}</th>
                                        <th class="px-2 py-2 font-medium">{{ $ti('issuesCol') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['rows'] as $row)
                                        <tr class="border-b border-slate-100 last:border-0"
                                            data-row="{{ $row['rowNumber'] }}"
                                            data-full-name="{{ $row['raw']['fullName'] ?? '' }}"
                                            data-phone="{{ $row['raw']['phone'] ?? '' }}"
                                            data-errors="{{ collect($row['errors'])->map(fn ($e) => $e['field'].'|'.$e['key'].'|'.($row['raw'][$e['field']] ?? ''))->implode(';') }}">
                                            <td class="px-2 py-1.5 text-slate-500">{{ $row['rowNumber'] }}</td>
                                            <td class="px-2 py-1.5 text-slate-700">{{ $row['raw']['fullName'] ?? '' }}</td>
                                            <td class="px-2 py-1.5 text-slate-700">{{ $row['raw']['phone'] ?? '' }}</td>
                                            <td class="px-2 py-1.5">
                                                @if ($row['errors'] === [])
                                                    <span class="text-emerald-700">{{ __('common.active') }}</span>
                                                @else
                                                    <span class="text-red-600">{{ __('patients.hasErrors') }}</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-1.5 text-red-600">
                                                {{ collect($row['errors'])->map(fn ($e) => PatientImport::IMPORT_ERROR_MESSAGES[$e['key']] ?? $e['key'])->implode('; ') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" name="step" value="import" class="btn-primary" @if ($preview['validCount'] === 0) disabled @endif>
                            {{ $ti('confirmImport', ['count' => $preview['validCount']]) }}
                        </button>
                        @if ($preview['errorCount'] > 0)
                            <button type="button" class="btn-secondary" id="download-error-report">{{ $ti('errorReport') }}</button>
                        @endif
                    </div>
                    <p class="text-xs text-slate-500">{{ $ti('confirmHint') }}</p>
                @endif
            </form>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var button = document.getElementById('download-error-report');
        var table = document.getElementById('import-preview-table');
        if (!button || !table) return;

        var messages = @json(PatientImport::IMPORT_ERROR_MESSAGES);

        function escapeCell(value) {
            value = value == null ? '' : String(value);
            return /[",\n\r]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
        }

        button.addEventListener('click', function () {
            var lines = [['row', 'field', 'issue', 'value'].map(escapeCell).join(',')];
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var errors = tr.getAttribute('data-errors');
                if (!errors) return;
                errors.split(';').forEach(function (part) {
                    var bits = part.split('|');
                    lines.push([tr.getAttribute('data-row'), bits[0], messages[bits[1]] || bits[1], bits[2]].map(escapeCell).join(','));
                });
            });
            var blob = new Blob(["\uFEFF" + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = 'patient-import-errors.csv';
            anchor.click();
            URL.revokeObjectURL(url);
        });
    })();
</script>
@endpush
