<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced password change (legacy PASSWORD_CHANGE_REQUIRED, three-layer rule 17).
 * Runs before the role check. While must_change_password is true every app page
 * bounces to /change-password — except the change-password page itself and
 * logout, so the user can always complete the change or leave.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            if (! $request->is('change-password', 'logout')) {
                return redirect()->route('password.change');
            }
        }

        return $next($request);
    }
}
