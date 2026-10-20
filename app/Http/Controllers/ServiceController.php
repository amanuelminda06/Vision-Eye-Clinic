<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Services\Categories;
use App\Support\Services\ServiceQueries;
use App\Support\Services\ServiceValidation;
use App\Support\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service catalogue (OWNER only), ported from src/modules/services/actions.ts
 * and the /dashboard/services pages.
 *
 * Rules preserved:
 * - `name` (historical display name) stays in sync with `name_en`.
 * - Price changes append a ServicePriceHistory row AND a SERVICE_PRICE_CHANGED
 *   audit; existing sale lines are never touched (they have their own snapshot).
 * - A no-op status toggle writes nothing.
 * - Image uploads are MIME-whitelisted and ≤ 1 MiB; replacing one removes the
 *   old blob best-effort.
 */
class ServiceController extends Controller
{
    /** GET /dashboard/services — list + filters. */
    public function index(Request $request): Response
    {
        $q = mb_substr((string) $request->query('q', ''), 0, 100);
        $categoryRaw = (string) $request->query('category', '');
        $category = Categories::isServiceCategory($categoryRaw) ? $categoryRaw : '';
        $statusRaw = (string) $request->query('status', '');
        $status = in_array($statusRaw, ['true', 'false'], true) ? $statusRaw : '';

        $filters = ['q' => $q, 'category' => $category, 'status' => $status];

        return response()->view('services.index', [
            'rows' => ServiceQueries::listServices($filters),
            'filters' => $filters,
        ]);
    }

    /** GET /dashboard/services/new — create form. */
    public function create(): Response
    {
        return response()->view('services.form', [
            'mode' => 'create',
            'service' => null,
            'action' => route('services.store'),
        ]);
    }

    /** POST /dashboard/services — create service. */
    public function store(Request $request): RedirectResponse
    {
        $validated = ServiceValidation::serviceForm($request->all(), $this->imageDescriptor($request));

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput($request->except('image'));
        }

        $values = $validated['values'];
        $imageUrl = $request->hasFile('image')
            ? $this->storeImage($request)
            : $values['imageUrl'];

        $service = Service::create([
            'name' => $values['nameEn'],
            'name_en' => $values['nameEn'],
            'name_am' => $values['nameAm'],
            'category' => $values['category'],
            'current_price' => $values['currentPrice'],
            'active' => true,
            'is_public' => $values['isPublic'],
            'public_description_en' => $values['publicDescriptionEn'],
            'public_description_am' => $values['publicDescriptionAm'],
            'receipt_label_en' => $values['receiptLabelEn'],
            'receipt_label_am' => $values['receiptLabelAm'],
            'display_order' => $values['displayOrder'],
            'image_url' => $imageUrl,
        ]);

        $actor = $request->user();
        Audit::write('Service', $service->id, 'SERVICE_CREATED', null, [
            'name' => $service->name,
            'nameAm' => $service->name_am,
            'category' => $service->category,
            'currentPrice' => Money::toDecimalString($service->current_price),
            'active' => $service->active,
            'isPublic' => $service->is_public,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect('/dashboard/services');
    }

    /** GET /dashboard/services/{id}/edit — edit form + price history. */
    public function edit(string $id): Response
    {
        $service = Service::find($id);

        if ($service === null) {
            abort(404);
        }

        return response()->view('services.form', [
            'mode' => 'edit',
            'service' => $service,
            'action' => route('services.update', $service->id),
            'priceHistory' => ServiceQueries::listPriceHistory($service->id),
        ]);
    }

    /** POST /dashboard/services/{id} — update service. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $before = Service::find($id);

        if ($before === null) {
            abort(404);
        }

        $validated = ServiceValidation::serviceForm($request->all(), $this->imageDescriptor($request));

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput($request->except('image'));
        }

        $values = $validated['values'];
        $beforePrice = Money::toDecimalString($before->current_price);
        $oldImageUrl = $before->image_url;

        $imageUrl = $request->hasFile('image')
            ? $this->storeImage($request)
            : ($values['imageUrl'] ?? $oldImageUrl);

        $before->fill([
            'name' => $values['nameEn'],
            'name_en' => $values['nameEn'],
            'name_am' => $values['nameAm'],
            'category' => $values['category'],
            'current_price' => $values['currentPrice'],
            'is_public' => $values['isPublic'],
            'public_description_en' => $values['publicDescriptionEn'],
            'public_description_am' => $values['publicDescriptionAm'],
            'receipt_label_en' => $values['receiptLabelEn'],
            'receipt_label_am' => $values['receiptLabelAm'],
            'display_order' => $values['displayOrder'],
            'image_url' => $imageUrl,
        ])->save();

        if ($request->hasFile('image') && $oldImageUrl !== null && $oldImageUrl !== $imageUrl) {
            $oldKey = Storage::keyFromUrl($oldImageUrl);
            if ($oldKey !== null) {
                try {
                    Storage::remove($oldKey);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        $actor = $request->user();
        Audit::write('Service', $id, 'SERVICE_UPDATED', [
            'name' => $before->getOriginal('name'),
            'nameAm' => $before->getOriginal('name_am'),
            'category' => $before->getOriginal('category'),
            'currentPrice' => $beforePrice,
            'isPublic' => (bool) $before->getOriginal('is_public'),
        ], [
            'name' => $before->name,
            'nameAm' => $before->name_am,
            'category' => $before->category,
            'currentPrice' => Money::toDecimalString($before->current_price),
            'isPublic' => $before->is_public,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        $newPrice = Money::toDecimalString($before->current_price);
        if ($beforePrice !== $newPrice) {
            $before->priceHistory()->create([
                'old_price' => $beforePrice,
                'new_price' => $newPrice,
                'changed_by' => $actor->id,
            ]);

            Audit::write('Service', $id, 'SERVICE_PRICE_CHANGED',
                ['currentPrice' => $beforePrice],
                ['currentPrice' => $newPrice],
                ['id' => $actor->id, 'name' => $actor->name],
            );
        }

        return redirect('/dashboard/services');
    }

    /** POST /dashboard/services/{id}/status — activate/deactivate. */
    public function setActive(Request $request, string $id): RedirectResponse
    {
        $service = Service::find($id);

        if ($service === null) {
            abort(404);
        }

        $active = (string) $request->input('active', '') === 'true';

        if ((bool) $service->active === $active) {
            return back();
        }

        $wasActive = (bool) $service->active;
        $service->forceFill(['active' => $active])->save();

        $actor = $request->user();
        Audit::write('Service', $id, 'SERVICE_STATUS_CHANGED',
            ['active' => $wasActive],
            ['active' => $active],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }

    /**
     * Reads the uploaded image descriptor for validation, or null when no file
     * was posted.
     *
     * @return array{mimeType: string, size: int}|null
     */
    private function imageDescriptor(Request $request): ?array
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');

        if (! $file->isValid()) {
            return ['mimeType' => 'application/octet-stream', 'size' => 0];
        }

        return ['mimeType' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()];
    }

    private function storeImage(Request $request): string
    {
        $file = $request->file('image');
        $ext = ServiceValidation::imageExtension((string) $file->getMimeType());

        if ($ext === null) {
            abort(422);
        }

        $key = 'service-'.(string) Str::ulid().'.'.$ext;
        Storage::put($key, (string) file_get_contents($file->getRealPath()));

        return '/uploads/'.$key;
    }
}
