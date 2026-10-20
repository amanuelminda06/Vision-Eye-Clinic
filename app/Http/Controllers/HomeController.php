<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * `/` is the PUBLIC marketing site — never the ERP (slice 7 builds the real
 * pages; this placeholder is replaced then).
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        return response()->view('home');
    }
}
