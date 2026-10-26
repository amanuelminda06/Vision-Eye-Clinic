@extends('layouts.app')

@section('title', $mode === 'edit' ? __('services.editService') : __('services.newService'))

@php
    use App\Support\Services\Categories;
    use App\Support\Services\ServiceValidation;

    $old = fn (string $key, $default = '') => old($key, $default);
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">
                {{ $mode === 'edit' ? __('services.editService') : __('services.newService') }}
            </h1>
            <a href="{{ route('services.index') }}" class="btn-secondary">{{ __('services.backToList') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="card space-y-4 p-4">
            @csrf

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="nameEn">{{ __('services.nameEn') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="nameEn" name="nameEn" maxlength="{{ ServiceValidation::MAX_SERVICE_NAME }}"
                           value="{{ $old('nameEn', $service->name_en ?? '') }}" @if ($errors->has('nameEn')) aria-invalid="true" @endif>
                    <p class="mt-1 text-xs text-slate-500">{{ __('services.nameEnHint') }}</p>
                    @error('nameEn')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="nameAm">{{ __('services.nameAm') }}</label>
                    <input class="input-field" id="nameAm" name="nameAm" maxlength="{{ ServiceValidation::MAX_SERVICE_NAME }}"
                           value="{{ $old('nameAm', $service->name_am ?? '') }}">
                    <p class="mt-1 text-xs text-slate-500">{{ __('services.nameAmHint') }}</p>
                    @error('nameAm')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="category">{{ __('services.category') }} <span class="text-red-500">*</span></label>
                    <select class="input-field" id="category" name="category">
                        <option value="">{{ __('services.chooseCategory') }}</option>
                        @foreach (Categories::SERVICE_CATEGORIES as $category)
                            <option value="{{ $category }}" @selected($old('category', $service->category ?? '') === $category)>
                                {{ __('services.'.Categories::labelKey($category)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('category')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="currentPrice">{{ __('services.currentPrice') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="currentPrice" name="currentPrice" inputmode="decimal"
                           value="{{ $old('currentPrice', $service ? \App\Support\Money::toDecimalString($service->current_price) : '') }}">
                    <p class="mt-1 text-xs text-slate-500">{{ __('services.priceHint') }}</p>
                    @error('currentPrice')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            </div>

            @if ($mode === 'edit')
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ __('services.priceChangeNote') }}</p>
            @endif

            <fieldset class="space-y-3 rounded-xl border border-brand-100 p-3">
                <legend class="px-1 text-sm font-medium text-slate-700">{{ __('services.publicTitle') }}</legend>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="isPublic" value="true"
                           @checked($old('isPublic', ($service->is_public ?? true) ? 'true' : '') === 'true')>
                    {{ __('services.publicLabel') }}
                </label>
                <p class="text-xs text-slate-500">{{ __('services.publicHint') }}</p>

                <div>
                    <label class="label text-xs" for="publicDescriptionEn">{{ __('services.publicDescriptionEn') }}</label>
                    <textarea class="input-field text-sm" id="publicDescriptionEn" name="publicDescriptionEn" rows="2"
                              maxlength="{{ ServiceValidation::MAX_PUBLIC_DESCRIPTION }}">{{ $old('publicDescriptionEn', $service->public_description_en ?? '') }}</textarea>
                    @error('publicDescriptionEn')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
                <div>
                    <label class="label text-xs" for="publicDescriptionAm">{{ __('services.publicDescriptionAm') }}</label>
                    <textarea class="input-field text-sm" id="publicDescriptionAm" name="publicDescriptionAm" rows="2"
                              maxlength="{{ ServiceValidation::MAX_PUBLIC_DESCRIPTION }}">{{ $old('publicDescriptionAm', $service->public_description_am ?? '') }}</textarea>
                    @error('publicDescriptionAm')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
                <p class="text-xs text-slate-500">{{ __('services.publicDescriptionHint') }}</p>
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="receiptLabelEn">{{ __('services.receiptLabelEn') }}</label>
                    <input class="input-field" id="receiptLabelEn" name="receiptLabelEn" maxlength="{{ ServiceValidation::MAX_SERVICE_NAME }}"
                           value="{{ $old('receiptLabelEn', $service->receipt_label_en ?? '') }}">
                    @error('receiptLabelEn')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="receiptLabelAm">{{ __('services.receiptLabelAm') }}</label>
                    <input class="input-field" id="receiptLabelAm" name="receiptLabelAm" maxlength="{{ ServiceValidation::MAX_SERVICE_NAME }}"
                           value="{{ $old('receiptLabelAm', $service->receipt_label_am ?? '') }}">
                    @error('receiptLabelAm')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            </div>
            <p class="-mt-2 text-xs text-slate-500">{{ __('services.receiptLabelsHint') }}</p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="displayOrder">{{ __('services.displayOrder') }}</label>
                    <input class="input-field" id="displayOrder" name="displayOrder" inputmode="numeric" min="0"
                           value="{{ $old('displayOrder', $service->display_order ?? 0) }}">
                    <p class="mt-1 text-xs text-slate-500">{{ __('services.displayOrderHint') }}</p>
                </div>
                <div>
                    <label class="label" for="imageUrl">{{ __('services.imageUrl') }}</label>
                    <input class="input-field" id="imageUrl" name="imageUrl" maxlength="{{ ServiceValidation::MAX_IMAGE_URL }}"
                           value="{{ $old('imageUrl', $service->image_url ?? '') }}">
                    <p class="mt-1 text-xs text-slate-500">{{ __('services.imageUrlHint') }}</p>
                    @error('imageUrl')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="label" for="image">{{ __('services.image') }}</label>
                <input class="input-field" id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp">
                <p class="mt-1 text-xs text-slate-500">{{ __('services.imageHint') }}</p>
                @error('image')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            @if ($mode === 'edit' && $service->image_url)
                <img src="{{ $service->image_url }}" alt="" class="h-20 rounded-lg border border-brand-100 object-contain">
            @endif

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="btn-primary">
                    {{ $mode === 'edit' ? __('common.save') : __('services.create') }}
                </button>
                <a href="{{ route('services.index') }}" class="btn-secondary">{{ __('common.cancel') }}</a>
            </div>
        </form>

        @if ($mode === 'edit')
            <section class="card p-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('services.priceHistory') }}</h2>
                <div class="mt-3 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="border-b border-brand-100 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-1">{{ __('services.changedAt') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('services.oldPrice') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('services.newPrice') }}</th>
                                <th class="px-2 py-1">{{ __('services.changedBy') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($priceHistory as $change)
                                <tr class="border-b border-brand-50 last:border-0">
                                    <td class="px-2 py-1 text-xs text-slate-500">{{ $change->changed_at?->toDateTimeString() }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ format_money($change->old_price) }}</td>
                                    <td class="px-2 py-1 text-right tabular-nums">{{ format_money($change->new_price) }}</td>
                                    <td class="px-2 py-1">{{ $change->changed_by_name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-2 py-4 text-center text-slate-500">{{ __('services.priceHistoryEmpty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
