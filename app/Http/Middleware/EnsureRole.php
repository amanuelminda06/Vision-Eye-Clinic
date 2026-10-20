<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role gate (legacy requirePageRole).
 *
 * GET with wrong role  → redirect to /403 (soft, leaks nothing).
 * Mutation with wrong role → 403 forbidden.
 *
 * Password-change enforcement runs BEFORE this (see EnsurePasswordChanged),
 * matching legacy PASSWORD_CHANGE_REQUIRED-before-FORBIDDEN ordering.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect(login_path());
        }

        $role = $user->role;

        if ($allowed !== [] && ! in_array($role, $allowed, true)) {
            if ($request->isMethod('GET')) {
                return redirect('/403');
            }

            abort(403, 'This action is not allowed for your role.');
        }

        return $next($request);
    }
}
