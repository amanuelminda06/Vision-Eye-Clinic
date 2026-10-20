<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard (slice 1 stub — widgets are built in slice 8).
 * Gate: any authenticated session, all three roles; forced password change
 * is enforced by the password.changed middleware before this runs.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return response()->view('dashboard', [
            'user' => $request->user(),
        ]);
    }
}
