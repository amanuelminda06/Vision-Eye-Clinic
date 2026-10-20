<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Support\Audit;
use App\Support\Shell\ShellValidation;
use App\Support\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff records (OWNER only). No hard deletes — only the `active` flag
 * flips. A staff member can never deactivate themselves.
 */
class StaffController extends Controller
{
    public const PAGE_SIZE = 20;

    /** GET /staff */
    public function index(Request $request): Response
    {
        $q = mb_substr((string) $request->query('q', ''), 0, 100);
        $status = (string) $request->query('status', '');

        $query = Staff::query()->orderBy('full_name');

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
            $query->where(function ($inner) use ($escaped): void {
                $inner->where('full_name', 'like', '%'.$escaped.'%')
                    ->orWhere('job_title', 'like', '%'.$escaped.'%');
            });
        }

        if ($status === 'true') {
            $query->where('active', true);
        } elseif ($status === 'false') {
            $query->where('active', false);
        }

        $total = (clone $query)->count();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) $request->query('page', 1)), $pageCount);

        $rows = $query
            ->skip(($page - 1) * self::PAGE_SIZE)
            ->take(self::PAGE_SIZE)
            ->get()
            ->map(fn (Staff $staff): array => [
                'id' => $staff->id,
                'fullName' => (string) $staff->full_name,
                'jobTitle' => (string) $staff->job_title,
                'isDoctor' => (bool) $staff->is_doctor,
                'specialty' => $staff->specialty,
                'phone' => $staff->phone,
                'photoUrl' => $staff->photo_url,
                'showOnWebsite' => (bool) $staff->show_on_website,
                'active' => (bool) $staff->active,
                'annualLeaveDays' => $staff->annual_leave_days,
            ])->all();

        return response()->view('staff.index', [
            'rows' => $rows,
            'q' => $q,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
        ]);
    }

    /** GET /staff/new */
    public function create(): Response
    {
        return response()->view('staff.form', [
            'staff' => null,
            'action' => route('staff.store'),
        ]);
    }

    /** POST /staff — create a staff record. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = ShellValidation::staffForm($request->all(), $this->photoDescriptor($request));

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $photoUrl = $request->hasFile('photo') ? $this->storePhoto($request) : null;

        $staff = Staff::create([
            'full_name' => $values['fullName'],
            'job_title' => $values['jobTitle'],
            'is_doctor' => $values['isDoctor'],
            'specialty' => $values['specialty'],
            'phone' => $values['phone'],
            'photo_url' => $photoUrl,
            'show_on_website' => $values['showOnWebsite'],
            'active' => true,
            'annual_leave_days' => $request->filled('annualLeaveDays')
                ? (int) $request->input('annualLeaveDays')
                : null,
        ]);

        Audit::write('Staff', $staff->id, 'STAFF_CREATED', null, [
            'fullName' => $staff->full_name,
            'jobTitle' => $staff->job_title,
            'isDoctor' => (bool) $staff->is_doctor,
            'specialty' => $staff->specialty,
            'phone' => $staff->phone,
            'showOnWebsite' => (bool) $staff->show_on_website,
            'photoUrl' => $staff->photo_url,
            'active' => true,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect('/staff')->with('status', __('users.updated'));
    }

    /** GET /staff/{id} — the edit form. */
    public function edit(string $id): Response
    {
        $staff = Staff::find($id);

        if ($staff === null) {
            abort(404);
        }

        return response()->view('staff.form', [
            'staff' => $staff,
            'action' => route('staff.update', $id),
        ]);
    }

    /** POST /staff/{id} — update a staff record. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $staff = Staff::find($id);

        if ($staff === null) {
            return back()->with('actionError', 'unknownError');
        }

        $validated = ShellValidation::staffForm($request->all(), $this->photoDescriptor($request));

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $oldPhotoUrl = $staff->photo_url;
        $photoUrl = $oldPhotoUrl;

        if ($request->hasFile('photo')) {
            $photoUrl = $this->storePhoto($request);

            if ($oldPhotoUrl !== null) {
                $this->removePhoto($oldPhotoUrl);
            }
        }

        $old = [
            'fullName' => (string) $staff->full_name,
            'jobTitle' => (string) $staff->job_title,
            'isDoctor' => (bool) $staff->is_doctor,
            'specialty' => $staff->specialty,
            'phone' => $staff->phone,
            'showOnWebsite' => (bool) $staff->show_on_website,
            'photoUrl' => $oldPhotoUrl,
        ];

        $staff->forceFill([
            'full_name' => $values['fullName'],
            'job_title' => $values['jobTitle'],
            'is_doctor' => $values['isDoctor'],
            'specialty' => $values['specialty'],
            'phone' => $values['phone'],
            'photo_url' => $photoUrl,
            'show_on_website' => $values['showOnWebsite'],
            'annual_leave_days' => $request->filled('annualLeaveDays')
                ? (int) $request->input('annualLeaveDays')
                : $staff->annual_leave_days,
        ])->save();

        Audit::write('Staff', $id, 'STAFF_UPDATED', $old, [
            'fullName' => $values['fullName'],
            'jobTitle' => $values['jobTitle'],
            'isDoctor' => $values['isDoctor'],
            'specialty' => $values['specialty'],
            'phone' => $values['phone'],
            'showOnWebsite' => $values['showOnWebsite'],
            'photoUrl' => $photoUrl,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect('/staff')->with('status', __('users.updated'));
    }

    /** POST /staff/{id}/status — activate / deactivate. */
    public function setStatus(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $staff = Staff::find($id);

        if ($staff === null) {
            return back()->with('actionError', 'unknownError');
        }

        $active = (string) $request->input('active', '') === 'true';

        if ((bool) $staff->active === $active) {
            return back();
        }

        $staff->forceFill(['active' => $active])->save();

        Audit::write('Staff', $id, 'STAFF_STATUS_CHANGED',
            ['active' => ! $active],
            ['active' => $active],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back()->with('status', $active ? __('users.reactivated') : __('users.deactivated'));
    }

    /**
     * @return array{mimeType: string, size: int}|null
     */
    private function photoDescriptor(Request $request): ?array
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        $file = $request->file('photo');

        if (! $file->isValid()) {
            return ['mimeType' => 'application/octet-stream', 'size' => 0];
        }

        return ['mimeType' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()];
    }

    private function storePhoto(Request $request): string
    {
        $file = $request->file('photo');
        $extension = ShellValidation::photoExtension((string) $file->getMimeType());

        if ($extension === null) {
            abort(422);
        }

        $key = 'staff-'.(string) Str::uuid().'.'.$extension;
        Storage::put($key, (string) file_get_contents($file->getRealPath()));

        return '/uploads/'.$key;
    }

    private function removePhoto(string $photoUrl): void
    {
        $key = Storage::keyFromUrl($photoUrl);

        if ($key === null) {
            return;
        }

        try {
            Storage::remove($key);
        } catch (\Throwable) {
            // Best-effort: a missing old blob must not fail the update.
        }
    }
}
