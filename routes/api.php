<?php

use App\Http\Controllers\PublicDoctorsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (session-free)
|--------------------------------------------------------------------------
|
| These routes live in the `api` middleware group: no session, no CSRF, no
| authentication. Only genuinely public data belongs here — the doctors feed
| returns a strict allow-list projection (never patient data).
|
*/

Route::get('/public/doctors', PublicDoctorsController::class)->name('public.doctors');
