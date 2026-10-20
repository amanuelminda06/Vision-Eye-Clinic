<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Passwords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service password change (forced + voluntary), ported from
 * changePasswordAction + changeUserPassword.
 *
 * Order: schema checks (required / passwordSame / passwordMismatch) →
 * verify current password → strength rules → save (must_change_password
 * clears) → audit PASSWORD_CHANGED with redacted hash halves.
 * Field errors are i18n KEYS; the view translates them.
 */
class ChangePasswordController extends Controller
{
    public function show(Request $request): Response
    {
        return response()->view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $current = (string) $request->input('currentPassword', '');
        $new = (string) $request->input('newPassword', '');
        $confirm = (string) $request->input('confirmPassword', '');

        // 1. Schema (legacy changePasswordSchema order: required → same → mismatch).
        $fields = [];
        if ($current === '') {
            $fields['currentPassword'] = 'required';
        }

        if ($new === '') {
            $fields['newPassword'] = 'required';
        }

        if ($confirm === '') {
            $fields['confirmPassword'] = 'required';
        }

        if ($new !== '' && $new === $current) {
            $fields['newPassword'] = 'passwordSame';
        }

        if ($new !== '' && $confirm !== '' && $new !== $confirm) {
            $fields['confirmPassword'] = 'passwordMismatch';
        }

        if ($fields !== []) {
            return back()->withErrors($fields)->onlyInput('currentPassword', 'newPassword', 'confirmPassword');
        }

        $user = User::find($request->user()->id);

        if ($user === null) {
            return back()->withErrors(['currentPassword' => 'currentPasswordWrong']);
        }

        // 2. Current password must match.
        if (! password_verify($current, $user->password)) {
            return back()
                ->withErrors(['currentPassword' => 'currentPasswordWrong'])
                ->onlyInput('currentPassword', 'newPassword', 'confirmPassword');
        }

        // 3. Strength: min 10, no common passwords, no personal info.
        $strengthError = Passwords::passwordError($new, [
            'email' => $user->email,
            'name' => $user->name,
        ]);

        if ($strengthError !== null) {
            return back()
                ->withErrors(['newPassword' => $strengthError])
                ->onlyInput('currentPassword', 'newPassword', 'confirmPassword');
        }

        // 4. Save: argon2id hash, clear the first-login force.
        $oldHash = $user->password;
        $newHash = Hash::make($new);

        $user->forceFill([
            'password' => $newHash,
            'must_change_password' => false,
        ])->save();

        // 5. Audit (both halves redact to "[REDACTED]" — never the hashes).
        Audit::write(
            'User',
            $user->id,
            'PASSWORD_CHANGED',
            ['passwordHash' => $oldHash],
            ['passwordHash' => $newHash],
            ['id' => $user->id, 'name' => $user->name],
        );

        // Session survives with no stale claim (role/mustChange read per request),
        // so the user lands straight on the dashboard.
        return redirect()->route('dashboard');
    }
}
