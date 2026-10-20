<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use App\Support\Audit;
use App\Support\Passwords;
use App\Support\Roles;
use App\Support\Shell\ShellValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user accounts (OWNER only).
 *
 * Rules preserved: no hard deletes (only the `active` flag flips), an
 * account can never deactivate itself, a no-op toggle writes nothing, and the
 * one-time temporary password is never logged or audited.
 */
class UserController extends Controller
{
    public const PAGE_SIZE = 20;

    /** GET /users */
    public function index(Request $request): Response
    {
        $q = mb_substr((string) $request->query('q', ''), 0, 100);
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $query = User::query()->orderBy('name');

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
            $query->where(function ($inner) use ($escaped): void {
                $inner->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('email', 'like', '%'.$escaped.'%');
            });
        }

        if (Roles::isRole($role)) {
            $query->where('role', $role);
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
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => (string) $user->role,
                'staffId' => $user->staff_id,
                'active' => (bool) $user->active,
                'mustChangePassword' => (bool) $user->must_change_password,
                'lastLoginAt' => $user->last_login_at,
                'createdAt' => $user->created_at,
            ])->all();

        return response()->view('users.index', [
            'rows' => $rows,
            'q' => $q,
            'role' => $role,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
        ]);
    }

    /** GET /users/new */
    public function create(): Response
    {
        return response()->view('users.form', [
            'user' => null,
            'action' => route('users.store'),
            'staffOptions' => Staff::query()->where('active', true)->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    /** POST /users — create an account. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $validated = ShellValidation::userForm($request->all(), true);

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];
        $password = (string) $request->input('password', '');

        $error = Passwords::passwordError($password, [
            'email' => $values['email'],
            'name' => $values['name'],
        ]);

        if ($error !== null) {
            return back()->withErrors(['password' => $error])->withInput();
        }

        try {
            $user = User::create([
                'name' => $values['name'],
                'email' => $values['email'],
                'password' => $password,
                'role' => $values['role'],
                'staff_id' => $values['staffId'],
                'active' => true,
                'must_change_password' => true,
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'emailTaken'])->withInput();
        }

        Audit::write('User', $user->id, 'USER_CREATED', null, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'staffId' => $user->staff_id,
            'active' => true,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect('/users')->with('status', __('users.created'));
    }

    /** GET /users/{id} — the edit form. */
    public function edit(string $id): Response
    {
        $user = User::find($id);

        if ($user === null) {
            abort(404);
        }

        return response()->view('users.form', [
            'user' => $user,
            'action' => route('users.update', $id),
            'staffOptions' => Staff::query()->where('active', true)->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    /** POST /users/{id} — update an account. */
    public function update(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $user = User::find($id);

        if ($user === null) {
            return back()->with('actionError', 'unknownError');
        }

        $validated = ShellValidation::userForm($request->all(), false);

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $old = [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => (string) $user->role,
            'staffId' => $user->staff_id,
        ];

        try {
            $user->forceFill([
                'name' => $values['name'],
                'email' => $values['email'],
                'role' => $values['role'],
                'staff_id' => $values['staffId'],
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'emailTaken'])->withInput();
        }

        Audit::write('User', $id, 'USER_UPDATED', $old, [
            'name' => $values['name'],
            'email' => $values['email'],
            'role' => $values['role'],
            'staffId' => $values['staffId'],
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return redirect('/users')->with('status', __('users.updated'));
    }

    /** POST /users/{id}/status — activate / deactivate. */
    public function setStatus(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $user = User::find($id);

        if ($user === null) {
            return back()->with('actionError', 'unknownError');
        }

        $active = (string) $request->input('active', '') === 'true';

        // An account can never deactivate itself.
        if (! $active && $user->id === $actor->id) {
            return back()->with('actionError', 'cannotDeactivateSelf');
        }

        if ((bool) $user->active === $active) {
            // A no-op toggle writes nothing and audits nothing.
            return back();
        }

        $user->forceFill(['active' => $active])->save();

        Audit::write('User', $id, 'USER_STATUS_CHANGED',
            ['active' => ! $active],
            ['active' => $active],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back()->with('status', $active ? __('users.reactivated') : __('users.deactivated'));
    }

    /** POST /users/{id}/reset-password — a one-time temporary password. */
    public function resetPassword(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $user = User::find($id);

        if ($user === null) {
            return back()->with('actionError', 'unknownError');
        }

        $temp = self::temporaryPassword();

        // The old flag is captured BEFORE the write (reading it afterwards
        // would return the new value and the diff would be empty).
        $oldMustChange = (bool) $user->must_change_password;

        $user->forceFill([
            'password' => $temp,
            'must_change_password' => true,
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        Audit::write('User', $id, 'USER_PASSWORD_RESET',
            ['mustChangePassword' => $oldMustChange],
            ['mustChangePassword' => true],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        // The temporary password is shown ONCE and never audited.
        return back()->with('tempPassword', $temp)->with('status', __('users.resetDone'));
    }

    /** POST /users/{id}/set-password — an owner-chosen password. */
    public function setPassword(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $user = User::find($id);

        if ($user === null) {
            return back()->with('actionError', 'unknownError');
        }

        $validated = ShellValidation::setPassword($request->all());

        if ($validated['fields'] !== []) {
            return back()->withErrors($validated['fields'])->withInput();
        }

        $values = $validated['values'];

        $error = Passwords::passwordError($values['password'], [
            'email' => (string) $user->email,
            'name' => (string) $user->name,
        ]);

        if ($error !== null) {
            return back()->withErrors(['password' => $error])->withInput();
        }

        $oldMustChange = (bool) $user->must_change_password;

        $user->forceFill([
            'password' => $values['password'],
            'must_change_password' => $values['mustChangePassword'],
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        Audit::write('User', $id, 'USER_PASSWORD_SET',
            ['passwordHash' => '[REDACTED]', 'mustChangePassword' => $oldMustChange],
            ['passwordHash' => '[REDACTED]', 'mustChangePassword' => $values['mustChangePassword']],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back()->with('status', __('users.passwordSet'));
    }

    /** A 14-character temporary password. */
    private static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
