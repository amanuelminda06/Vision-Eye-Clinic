@extends('layouts.app')

@section('title', $mode === 'create' ? __('patients.newPatient') : __('patients.editPatient'))

@php
    use App\Support\Patients\PatientValidation;
    use App\Support\Phone;

    $tv = fn (?string $key) => $key === null ? null : field_error($key);

    $isEdit = $mode === 'edit';
    $phoneValue = old('phone', $patient?->phone_e164 ? Phone::display($patient->phone_e164) : '');
    $similar = session('similarPatients', []);
    $duplicate = session('duplicatePatient');
    // Round-2 submit carries confirm=true once the amber panel was shown.
    $showConfirm = $similar !== [];
@endphp

@section('content')
    <div class="space-y-4">
        <h1 class="text-xl font-semibold text-slate-900">
            {{ $isEdit ? __('patients.editPatient') : __('patients.newPatient') }}
        </h1>

        <form method="POST" action="{{ $isEdit ? route('patients.update', $patient->id) : route('patients.store') }}"
              enctype="multipart/form-data" class="card max-w-2xl space-y-4 p-6">
            @csrf
            @if ($showConfirm)
                <input type="hidden" name="confirm" value="true">
            @endif

            <div>
                <label class="label" for="fullName">{{ __('patients.fullName') }}</label>
                <input class="input-field" id="fullName" name="fullName" type="text" required autocomplete="off"
                       maxlength="120" value="{{ old('fullName', $patient?->full_name ?? '') }}"
                       @if ($errors->has('fullName')) aria-invalid="true" @endif>
                @error('fullName')
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                @enderror
            </div>

            <div>
                <label class="label" for="phone">{{ __('patients.phone') }}</label>
                <input class="input-field" id="phone" name="phone" type="tel" inputmode="tel" placeholder="0912345678"
                       required autocomplete="off" value="{{ $phoneValue }}"
                       @if ($errors->has('phone')) aria-invalid="true" @endif>
                <p class="mt-1 text-xs text-slate-500">{{ __('patients.phoneHint') }}</p>
                @error('phone')
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="sex">{{ __('patients.sex') }}</label>
                    <select class="input-field" id="sex" name="sex">
                        <option value="" @selected(old('sex', $patient?->sex ?? '') === '')>{{ __('patients.sexUnknown') }}</option>
                        <option value="MALE" @selected(old('sex', $patient?->sex) === 'MALE')>{{ __('patients.sexMale') }}</option>
                        <option value="FEMALE" @selected(old('sex', $patient?->sex) === 'FEMALE')>{{ __('patients.sexFemale') }}</option>
                        <option value="OTHER" @selected(old('sex', $patient?->sex) === 'OTHER')>{{ __('patients.sexOther') }}</option>
                    </select>
                    @error('sex')
                        <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                    @enderror
                </div>

                <div>
                    <label class="label" for="age">{{ __('patients.age') }}</label>
                    <input class="input-field" id="age" name="age" type="number" inputmode="numeric"
                           min="{{ PatientValidation::MIN_AGE }}" max="{{ PatientValidation::MAX_AGE }}"
                           value="{{ old('age', $patient?->age ?? '') }}"
                           @if ($errors->has('age')) aria-invalid="true" @endif>
                    @error('age')
                        <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                    @enderror
                </div>

                <div>
                    <label class="label" for="preferredLanguage">{{ __('common.language') }}</label>
                    <select class="input-field" id="preferredLanguage" name="preferredLanguage">
                        <option value="" @selected(old('preferredLanguage', $patient?->preferred_language ?? '') === '')>{{ __('patients.languageDefault') }}</option>
                        <option value="en" @selected(old('preferredLanguage', $patient?->preferred_language) === 'en')>{{ __('patients.langEn') }}</option>
                        <option value="am" @selected(old('preferredLanguage', $patient?->preferred_language) === 'am')>{{ __('patients.langAm') }}</option>
                    </select>
                    @error('preferredLanguage')
                        <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label class="label" for="notesNonClinical">{{ __('patients.notes') }}</label>
                <textarea class="input-field min-h-24" id="notesNonClinical" name="notesNonClinical" rows="3"
                          maxlength="2000">{{ old('notesNonClinical', $patient?->notes_non_clinical ?? '') }}</textarea>
                <p class="mt-1 text-xs text-amber-700">{{ __('patients.notesHint') }}</p>
                @error('notesNonClinical')
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                @enderror
            </div>

            @if ($idPhotosEnabled ?? false)
            <div>
                <label class="label" for="idPhoto">{{ __('patients.idPhoto') }}</label>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($patient?->id_photo_url)
                        <img src="{{ $patient->id_photo_url }}" alt="" data-id-photo-thumb
                             class="h-16 w-16 rounded-lg border border-slate-200 object-cover">
                    @else
                        <div data-id-photo-thumb class="hidden h-16 w-16 rounded-lg border border-slate-200 object-cover"></div>
                    @endif
                    <input id="idPhoto" name="idPhoto" type="file" accept="image/png,image/jpeg,image/webp"
                           @if ($errors->has('idPhoto')) aria-invalid="true" @endif>
                    <button type="button" class="btn-secondary text-xs" data-id-photo-upload>{{ __('patients.idPhotoUpload') }}</button>
                    <button type="button" class="btn-secondary text-xs" data-id-photo-capture>{{ __('patients.idPhotoCapture') }}</button>
                    @if ($patient?->id_photo_url)
                        <button type="button" class="btn-secondary text-xs" data-id-photo-view>{{ __('patients.idPhotoView') }}</button>
                    @endif
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('patients.idPhotoHint') }}</p>
                @error('idPhoto')
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                @enderror
            </div>
            @endif

            @if ($isEdit && $patient?->system_id)
            <div>
                <label class="label" for="systemId">{{ __('patients.systemId') }}</label>
                <input class="input-field bg-slate-50 font-mono text-slate-600" id="systemId" type="text"
                       value="{{ $patient->system_id }}" readonly disabled>
                <p class="mt-1 text-xs text-slate-500">{{ __('patients.systemIdAuto') }}</p>
            </div>
            @endif

            <div>
                <label class="label" for="idNo">{{ __('patients.idNo') }}</label>
                <input class="input-field" id="idNo" name="idNo" type="text" maxlength="32"
                       placeholder="{{ __('patients.idNoHint') }}" value="{{ old('idNo', $patient?->id_no ?? '') }}">
                @if (! $isEdit)
                    <p class="mt-1 text-xs text-slate-500">{{ __('patients.idNoHelp') }}</p>
                @endif
                @error('idNo')
                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>
                @enderror
            </div>

            @if ($showConfirm)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-medium">{{ __('patients.similarTitle') }}</p>
                    <p class="mt-1">{{ __('patients.similarBody') }}</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($similar as $person)
                            <li><a href="{{ route('patients.show', $person['id']) }}" class="font-medium hover:underline">{{ $person['fullName'] }}</a></li>
                        @endforeach
                    </ul>
                    <p class="mt-2">{{ __('patients.similarConfirmHint') }}</p>
                </div>
            @endif

            @if ($duplicate)
                <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p>{{ __('common.phoneTaken') }}</p>
                    <a href="{{ route('patients.show', $duplicate['id']) }}" class="mt-1 inline-block font-medium hover:underline">
                        {{ __t('patients.openExisting', ['name' => $duplicate['fullName']]) }}
                    </a>
                </div>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary">{{ $isEdit ? __('patients.update') : __('patients.create') }}</button>
                <a href="{{ $route }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>
    </div>

    @if ($patient?->id_photo_url)
        <div data-id-photo-lightbox class="fixed inset-0 z-50 hidden items-center justify-center p-4">
            <button type="button" aria-label="{{ __('common.close') }}" class="absolute inset-0 bg-slate-900/70" data-id-photo-lightbox-close></button>
            <div role="dialog" aria-modal="true" aria-label="{{ __('patients.idPhotoView') }}"
                 class="relative z-10 max-h-[90vh] max-w-5xl overflow-auto rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                <img src="{{ $patient->id_photo_url }}" alt="" class="h-auto w-full max-w-full object-contain">
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        var input = document.getElementById('idPhoto');
        if (!input) return;
        var upload = document.querySelector('[data-id-photo-upload]');
        var capture = document.querySelector('[data-id-photo-capture]');
        var view = document.querySelector('[data-id-photo-view]');
        var lightbox = document.querySelector('[data-id-photo-lightbox]');
        var close = document.querySelector('[data-id-photo-lightbox-close]');
        var thumb = document.querySelector('[data-id-photo-thumb]');
        var objectUrl = null;

        if (upload) upload.addEventListener('click', function () {
            input.removeAttribute('capture');
            input.click();
        });
        if (capture) capture.addEventListener('click', function () {
            input.setAttribute('capture', 'environment');
            input.click();
        });
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (!file || !thumb) return;
            objectUrl = URL.createObjectURL(file);
            thumb.src = objectUrl;
            thumb.classList.remove('hidden');
            if (view) view.classList.remove('hidden');
        });
        if (view && lightbox) view.addEventListener('click', function () {
            lightbox.classList.remove('hidden');
            lightbox.classList.add('flex');
        });
        if (close && lightbox) close.addEventListener('click', function () {
            lightbox.classList.add('hidden');
            lightbox.classList.remove('flex');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && lightbox && !lightbox.classList.contains('hidden')) {
                lightbox.classList.add('hidden');
                lightbox.classList.remove('flex');
            }
        });
    })();
</script>
@endpush

