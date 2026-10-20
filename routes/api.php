<?php

use App\Http\Controllers\MessageApiController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicDoctorsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (session-free)
|--------------------------------------------------------------------------
|
| These routes live in the `api` middleware group: no session, no CSRF, no
| authentication. Only genuinely public data belongs here — the doctors feed
| returns a strict allow-list projection (never patient data) and the booking
| intake creates an appointment for a visitor who never signed in.
|
*/

Route::get('/public/doctors', PublicDoctorsController::class)->name('public.doctors');

Route::post('/public/requests', PublicBookingController::class)->name('public.requests');

// The message badge — session only (no role check, like the legacy).
Route::get('/messages/count', [MessageApiController::class, 'count'])
    ->middleware('auth')
    ->name('messages.count');

// The cron entry for the message jobs (bearer CRON_SECRET).
Route::post('/jobs/messages', [MessageApiController::class, 'jobs'])->name('jobs.messages');
