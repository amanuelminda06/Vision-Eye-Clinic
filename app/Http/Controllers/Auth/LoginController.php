<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secret-path login, ported from src/modules/auth (login page + verifyCredentials).
 *
 * Exact legacy order: empty input → fail (no DB/audit); per-IP rate limit
 * (30/5 min) → fail silently (no audit); lookup + verify against a dummy
 * argon2id hash for constant time; failure counter/lock on the user row;
 * LOGIN_FAILED / LOGIN_SUCCESS audit rows; generic invalidCredentials error.
 */
class LoginController extends Controller
{
    /**
     * Valid argon2id hash of a throwaway string — equalizes timing when no
     * user matches (ported from verify-credentials.ts).
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHRzYWx0$c29tZWhhc2hlZGhhc2h2YWx1ZQ';

    /** GET /login/{secret} — wrong secret is a real 404. */
    public function show(Request $request, string $secret): Response
    {
        $this->guardSecret($secret);

        return response()->view('auth.login', [
            'email' => old('email'),
        ]);
    }

    /** POST /login/{secret} */
    public function attempt(Request $request, string $secret): RedirectResponse
    {
        $this->guardSecret($secret);

        $email = mb_strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        // 1. Empty input: no DB, no audit.
        if ($email === '' || $password === '') {
            return $this->failed($request);
        }

        $ip = Audit::clientIp($request);

        // 2. Per-IP rate limit before any DB work (blocked → not audited).
        if (! LoginPolicy::checkRateLimit($ip)) {
            return $this->failed($request);
        }

        $user = User::where('email', $email)->first();

        // 3. Constant-time verify (dummy hash when the user is unknown).
        $passwordOk = password_verify($password, $user?->password ?? self::DUMMY_HASH);

        // 4. Failure: increment counter (5th failure locks 15 min), audit.
        if ($user === null || ! $passwordOk) {
            if ($user !== null) {
                LoginPolicy::recordFailure($user);
            }

            Audit::write(
                'User',
                $user?->id ?? 'email:'.$email,
                'LOGIN_FAILED',
                null,
                ['reason' => $user !== null ? 'invalidPassword' : 'unknownEmail'],
                $user !== null ? ['id' => $user->id, 'name' => $user->name] : null,
                $ip,
            );

            return $this->failed($request);
        }

        // 5. Locked: correct password but inside the lock window — audit, no counter change.
        if (LoginPolicy::isLocked($user->locked_until)) {
            Audit::write('User', $user->id, 'LOGIN_FAILED', null, ['reason' => 'locked'],
                ['id' => $user->id, 'name' => $user->name], $ip);

            return $this->failed($request);
        }

        // 6. Inactive account.
        if (! $user->active) {
            Audit::write('User', $user->id, 'LOGIN_FAILED', null, ['reason' => 'inactive'],
                ['id' => $user->id, 'name' => $user->name], $ip);

            return $this->failed($request);
        }

        // 7. Success: reset counters, stamp last_login_at, audit, session.
        LoginPolicy::recordSuccess($user);

        Audit::write('User', $user->id, 'LOGIN_SUCCESS', null, null,
            ['id' => $user->id, 'name' => $user->name], $ip);

        Auth::login($user, $remember = false);
        $request->session()->regenerate();

        // First-login forced change goes straight to /change-password,
        // otherwise the dashboard (the app root `/` is the public site).
        return redirect()->route($user->must_change_password ? 'password.change' : 'dashboard');
    }

    private function guardSecret(string $secret): void
    {
        $expected = (string) config('auth.login_secret_path');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }
    }

    /** Every failure class shows the same generic message (never which check failed). */
    private function failed(Request $request): RedirectResponse
    {
        return back()
            ->withErrors(['login' => 'invalidCredentials'])
            ->withInput($request->only('email'));
    }
}
